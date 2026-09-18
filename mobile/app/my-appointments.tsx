import axios from 'axios';
import { router } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api } from '../lib/api';

interface Appointment {
  id: number;
  scheduled_at: string;
  reason: string;
  status: 'booked' | 'cancelled' | 'checked_in' | 'no_show' | 'completed';
  cancel_reason: string | null;
}

const STATUS_STYLES: Record<Appointment['status'], string> = {
  booked: 'bg-slate-100 text-slate-700',
  checked_in: 'bg-blue-100 text-blue-700',
  completed: 'bg-green-100 text-green-700',
  cancelled: 'bg-gray-100 text-gray-500',
  no_show: 'bg-red-100 text-red-700',
};

const STATUS_LABELS: Record<Appointment['status'], string> = {
  booked: 'Booked',
  checked_in: 'Checked in',
  completed: 'Completed',
  cancelled: 'Cancelled',
  no_show: 'No-show',
};

function formatDateTime(iso: string) {
  const d = new Date(iso);
  return d.toLocaleString([], {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function AppointmentCard({
  appointment,
  onCancel,
  isCancelling,
}: {
  appointment: Appointment;
  onCancel?: (id: number) => void;
  isCancelling: boolean;
}) {
  const canCancel = onCancel && (appointment.status === 'booked' || appointment.status === 'checked_in');

  return (
    <View className="mb-3 rounded-xl border border-slate-200 bg-white p-4">
      <View className="flex-row items-center justify-between">
        <Text className="text-sm font-semibold text-slate-900">
          {formatDateTime(appointment.scheduled_at)}
        </Text>
        <Text
          className={`rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_STYLES[appointment.status]}`}
        >
          {STATUS_LABELS[appointment.status]}
        </Text>
      </View>
      <Text className="mt-2 text-sm text-slate-600">{appointment.reason}</Text>
      {appointment.cancel_reason && (
        <Text className="mt-1 text-xs text-slate-400">Cancelled: {appointment.cancel_reason}</Text>
      )}
      {canCancel && (
        <Pressable
          disabled={isCancelling}
          onPress={() => onCancel!(appointment.id)}
          className="mt-3 items-center rounded-lg border border-red-200 py-2 disabled:opacity-50"
        >
          <Text className="text-sm font-semibold text-red-600">
            {isCancelling ? 'Cancelling…' : 'Cancel appointment'}
          </Text>
        </Pressable>
      )}
    </View>
  );
}

export default function MyAppointments() {
  const insets = useSafeAreaInsets();

  const [upcoming, setUpcoming] = useState<Appointment[]>([]);
  const [past, setPast] = useState<Appointment[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [cancellingId, setCancellingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    const response = await api.get<{ upcoming: Appointment[]; past: Appointment[] }>('/appointments/mine');
    setUpcoming(response.data.upcoming);
    setPast(response.data.past);
  }, []);

  useEffect(() => {
    load().finally(() => setIsLoading(false));
  }, [load]);

  async function handleRefresh() {
    setIsRefreshing(true);
    await load();
    setIsRefreshing(false);
  }

  function confirmCancel(id: number) {
    Alert.alert('Cancel appointment?', 'This will free up the slot for other patients.', [
      { text: 'Keep it', style: 'cancel' },
      { text: 'Cancel appointment', style: 'destructive', onPress: () => handleCancel(id) },
    ]);
  }

  async function handleCancel(id: number) {
    setError(null);
    setCancellingId(id);
    try {
      await api.post(`/appointments/${id}/cancel`);
      await load();
    } catch (err) {
      if (axios.isAxiosError(err) && err.response) {
        const data = err.response.data as { message?: string };
        setError(data.message ?? 'Unable to cancel this appointment.');
      } else {
        setError('Unable to cancel this appointment.');
      }
    } finally {
      setCancellingId(null);
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
    <ScrollView
      className="flex-1 bg-white"
      contentContainerStyle={{ paddingTop: insets.top + 24, paddingBottom: 48 }}
      refreshControl={<RefreshControl refreshing={isRefreshing} onRefresh={handleRefresh} />}
    >
      <View className="px-6">
        <View className="flex-row items-center justify-between">
          <Text className="text-2xl font-bold text-slate-900">My Appointments</Text>
          <Pressable onPress={() => router.back()}>
            <Text className="text-sm text-slate-400">Close</Text>
          </Pressable>
        </View>

        {error && (
          <Text className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>
        )}

        <Pressable
          onPress={() => router.push('/book-appointment')}
          className="mt-6 items-center rounded-lg bg-[#0b1636] py-3.5"
        >
          <Text className="text-base font-semibold text-white">Book a new appointment</Text>
        </Pressable>

        <Text className="mb-3 mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">
          Upcoming
        </Text>
        {upcoming.length === 0 && (
          <Text className="text-sm text-slate-400">No upcoming appointments.</Text>
        )}
        {upcoming.map((appointment) => (
          <AppointmentCard
            key={appointment.id}
            appointment={appointment}
            onCancel={confirmCancel}
            isCancelling={cancellingId === appointment.id}
          />
        ))}

        <Text className="mb-3 mt-6 text-sm font-semibold uppercase tracking-wide text-slate-500">
          Past
        </Text>
        {past.length === 0 && <Text className="text-sm text-slate-400">No past appointments.</Text>}
        {past.map((appointment) => (
          <AppointmentCard key={appointment.id} appointment={appointment} isCancelling={false} />
        ))}
      </View>
    </ScrollView>
  );
}
