import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api } from '../lib/api';
import { openCertificate, type Certificate } from '../lib/certificates';

interface Visit {
  id: number;
  date: string;
  complaint: string;
  vitals: { blood_pressure: string | null; temperature: string | null; pulse: number | null; resp_rate: number | null };
  diagnosis: string;
  treatment: string | null;
  referred_to: string | null;
  created_by: string;
  certificate: Certificate | null;
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <View className="mt-4">
      <Text className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</Text>
      <Text className="mt-1 text-sm text-slate-800">{value}</Text>
    </View>
  );
}

export default function VisitDetail() {
  const insets = useSafeAreaInsets();
  const { queueEntryId } = useLocalSearchParams<{ queueEntryId: string }>();
  const [visit, setVisit] = useState<Visit | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [isOpening, setIsOpening] = useState(false);

  useEffect(() => {
    api
      .get<Visit>(`/visits/${queueEntryId}`)
      .then((response) => setVisit(response.data))
      .catch(() => setError('Unable to load this visit record.'))
      .finally(() => setIsLoading(false));
  }, [queueEntryId]);

  async function handleOpenCertificate() {
    if (!visit?.certificate) return;
    setError(null);
    setIsOpening(true);
    try {
      await openCertificate(visit.certificate);
    } catch {
      setError('Unable to open the certificate. Check your connection and try again.');
    } finally {
      setIsOpening(false);
    }
  }

  if (isLoading) {
    return (
      <View className="flex-1 items-center justify-center bg-white">
        <ActivityIndicator />
      </View>
    );
  }

  return (
    <ScrollView className="flex-1 bg-white" contentContainerStyle={{ paddingTop: insets.top + 24, paddingBottom: 48 }}>
      <View className="px-6">
        <View className="flex-row items-center justify-between">
          <Text className="text-2xl font-bold text-slate-900">Visit Record</Text>
          <Pressable onPress={() => router.back()}>
            <Text className="text-sm text-slate-400">Close</Text>
          </Pressable>
        </View>

        {error && (
          <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>
        )}

        {visit && (
          <>
            <Text className="mt-2 text-sm text-slate-500">
              {new Date(visit.date).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })} · Seen by{' '}
              {visit.created_by}
            </Text>

            <Field label="Complaint" value={visit.complaint} />
            <Field
              label="Vitals"
              value={`BP ${visit.vitals.blood_pressure ?? '—'} · Temp ${visit.vitals.temperature ?? '—'}°C · Pulse ${
                visit.vitals.pulse ?? '—'
              } · RR ${visit.vitals.resp_rate ?? '—'}`}
            />
            <Field label="Diagnosis" value={visit.diagnosis} />
            <Field label="Treatment" value={visit.treatment ?? '—'} />
            <Field label="Referred to" value={visit.referred_to ?? '—'} />

            {visit.certificate && (
              <View className="mt-8 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <Text className="text-xs font-medium uppercase tracking-wide text-slate-500">
                  Medical certificate
                </Text>
                <Text className="mt-1 text-sm font-semibold text-slate-900">
                  {visit.certificate.certificate_no}
                  {visit.certificate.rest_days
                    ? ` · ${visit.certificate.rest_days} rest ${visit.certificate.rest_days === 1 ? 'day' : 'days'}`
                    : ' · fit to return'}
                </Text>
                <Text className="text-xs text-slate-500">Issued by {visit.certificate.issued_by}</Text>
                <Pressable
                  onPress={handleOpenCertificate}
                  disabled={isOpening}
                  className="mt-4 items-center rounded-lg bg-brand py-3 disabled:opacity-50"
                >
                  {isOpening ? (
                    <ActivityIndicator color="white" />
                  ) : (
                    <Text className="font-semibold text-white">Download certificate</Text>
                  )}
                </Pressable>
              </View>
            )}
          </>
        )}
      </View>
    </ScrollView>
  );
}
