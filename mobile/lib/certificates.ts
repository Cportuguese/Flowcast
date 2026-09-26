import { Directory, File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import { api, getToken } from './api';

// Shape of `certificate` on visit payloads (CertificateController::format).
export interface Certificate {
  id: number;
  certificate_no: string;
  rest_days: number | null;
  valid_from: string;
  valid_until: string | null;
  issued_by: string;
  issued_at: string;
  download_url: string;
}

/**
 * The PDF sits behind the bearer token, so it can't just be opened by URL.
 * Download it into the cache with the auth header, then hand it to the
 * OS share sheet (which on both platforms offers "open" and "save").
 */
export async function openCertificate(certificate: Certificate) {
  const token = await getToken();
  const directory = new Directory(Paths.cache, 'certificates');
  directory.create({ idempotent: true, intermediates: true });

  const file = await File.downloadFileAsync(
    `${api.defaults.baseURL}${certificate.download_url}`,
    new File(directory, `${certificate.certificate_no}.pdf`),
    { idempotent: true, headers: { Authorization: `Bearer ${token}`, Accept: 'application/pdf' } },
  );

  try {
    await Sharing.shareAsync(file.uri, {
      mimeType: 'application/pdf',
      UTI: 'com.adobe.pdf',
      dialogTitle: `Medical certificate ${certificate.certificate_no}`,
    });
  } finally {
    // It's a medical document: don't leave a copy in the app cache once the
    // share sheet has closed. Whatever the user chose ("save", "open in")
    // already has its own copy by now.
    try {
      file.delete();
    } catch {
      // Best effort — the OS may already have cleaned it up.
    }
  }
}
