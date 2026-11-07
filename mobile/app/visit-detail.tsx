import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, View } from 'react-native';
import { Text } from '../components/Text';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api } from '../lib/api';
import { formatLongDate, formatTime } from '../lib/time';

/** What the clinic tells a patient about the certificate it issued for a visit. */
interface Certificate {
  certificate_no: string;
  rest_days: number | null;
  issued_by: string;
}

interface Visit {
  id: number;
  date: string;
  complaint: string;
  vitals: { blood_pressure: string | null; temperature: string | null; pulse: number | null; resp_rate: number | null };
  intervention: string;
  remarks: string | null;
  referred_to: string | null;
  created_by: string;
  created_by_title: string | null;
  medicines: Array<{ id: number; name: string; unit: string; quantity: number }>;
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
  const { visitId } = useLocalSearchParams<{ visitId: string }>();
  const [visit, setVisit] = useState<Visit | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api
      .get<Visit>(`/visits/${visitId}`)
      .then((response) => setVisit(response.data))
      .catch(() => setError('Unable to load this visit record.'))
      .finally(() => setIsLoading(false));
  }, [visitId]);

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
          <Pressable onPress={() => router.back()} hitSlop={8}>
            <Text className="text-sm text-slate-400">Close</Text>
          </Pressable>
        </View>

        {error && <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>}

        {visit && (
          <>
            <Text className="mt-2 text-sm text-slate-500">
              {formatLongDate(visit.date)} · {formatTime(visit.date)} · Seen by {visit.created_by}
              {visit.created_by_title ? ` (${visit.created_by_title})` : ''}
            </Text>

            <Field label="Complaint" value={visit.complaint} />
            <Field
              label="Vitals"
              value={`BP ${visit.vitals.blood_pressure ?? '—'} · Temp ${visit.vitals.temperature ?? '—'}°C · Pulse ${
                visit.vitals.pulse ?? '—'
              } · RR ${visit.vitals.resp_rate ?? '—'}`}
            />
            <Field label="Intervention" value={visit.intervention} />
            {visit.medicines.length > 0 && (
              <Field
                label="Medicines given"
                value={visit.medicines.map((m) => `${m.name} × ${m.quantity} ${m.unit}`).join(', ')}
              />
            )}
            <Field label="Referred to" value={visit.referred_to ?? '—'} />
            <Field label="Remarks" value={visit.remarks ?? '—'} />

            {visit.certificate && (
              <View className="mt-8 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <Text className="text-xs font-medium uppercase tracking-wide text-slate-500">Medical certificate</Text>
                <Text className="mt-1 text-sm font-semibold text-slate-900">
                  {visit.certificate.certificate_no}
                  {visit.certificate.rest_days
                    ? ` · ${visit.certificate.rest_days} rest ${visit.certificate.rest_days === 1 ? 'day' : 'days'}`
                    : ' · fit to return'}
                </Text>
                <Text className="text-xs text-slate-500">Issued by {visit.certificate.issued_by}</Text>
                <Text className="mt-3 text-xs text-slate-500">
                  Certificates are handed out by the clinic. Ask the nurse for your signed copy.
                </Text>
              </View>
            )}
          </>
        )}
      </View>
    </ScrollView>
  );
}
