import { api } from './api'

// Shape of `certificate` on visit payloads (CertificateController::format).
export interface Certificate {
  id: number
  certificate_no: string
  rest_days: number | null
  remarks: string | null
  valid_from: string
  valid_until: string | null
  issued_by: string
  issued_at: string
  download_url: string
}

/**
 * The PDF sits behind the bearer token, so a plain <a href> can't fetch it.
 * Pull it through axios and hand the blob to the browser as a download.
 */
export async function downloadCertificate(certificate: Certificate) {
  const response = await api.get<Blob>(certificate.download_url, { responseType: 'blob' })
  const url = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = url
  link.download = `${certificate.certificate_no}.pdf`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
