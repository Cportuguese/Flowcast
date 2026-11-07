import Ionicons from '@expo/vector-icons/Ionicons';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, RefreshControl, ScrollView, View } from 'react-native';
import { Text } from '../../components/Text';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { OfflineNotice } from '../../components/OfflineNotice';
import { ScreenHeader } from '../../components/ScreenHeader';
import { useAuth } from '../../context/AuthContext';
import { api } from '../../lib/api';
import {
  LATE_GRACE_MINUTES,
  STATUS_LABELS,
  STATUS_STYLES,
  minutesPastBooking,
  peopleAheadText,
  providerLine,
  type Appointment,
} from '../../lib/appointments';
import { fetchWithCache, isNetworkError } from '../../lib/cache';
import { extractErrorMessage } from '../../lib/errors';
import { useAppointmentUpdates } from '../../lib/realtime';
import { formatShortDay, formatTime } from '../../lib/time';

/** What the patient needs to know about a booking's state, in one line. */
function StatusNote({ appointment, isPast }: { appointment: Appointment; isPast: boolean }) {
  if (appointment.status === 'pending') {
    return isPast ? (
      <Text className="mt-3 text-xs text-slate-500">This request wasn't reviewed before its time. Please book again.</Text>
    ) : (
      <View className="mt-3 flex-row items-start gap-2 rounded-lg bg-amber-50 p-3">
        <Ionicons name="hourglass-outline" size={16} color="#b45309" />
        <Text className="flex-1 text-xs text-amber-900">
          Your booking is under review. Nurse Portia will confirm it, and you'll be notified.
        </Text>
      </View>
    );
  }

  if (appointment.status === 'declined') {
    return (
      <Text className="mt-3 text-xs text-red-700">
        Not confirmed{appointment.review_note ? `: ${appointment.review_note}` : '.'} You can book another time.
      </Text>
    );
  }

  if (appointment.status === 'booked' && !isPast && minutesPastBooking(appointment) >= 0) {
    // Its time has come today but the patient has not been seen yet.
    const late = minutesPastBooking(appointment);
    return (
      <View className="mt-3 rounded-lg bg-blue-50 p-3">
        <Text className="text-xs text-blue-800">
          {!appointment.goes_through_queue
            ? "Your booked time has come. If you haven't been called in yet, let the clinic staff know you're here."
            : late <= LATE_GRACE_MINUTES
              ? 'Your time is now — check in from the Home tab to keep your place in line.'
              : "Your booked time has passed. You can still check in from the Home tab; you'll join the line in order."}
        </Text>
      </View>
    );
  }

  if (appointment.status === 'checked_in' && !isPast) {
    return <Text className="mt-3 text-xs text-blue-700">You're checked in — your queue number is on the Home tab.</Text>;
  }

  if (appointment.status === 'booked' && !isPast) {
    return (
      <View className="mt-3 rounded-lg bg-blue-50 p-3">
        <Text className="text-xs font-medium uppercase tracking-wide text-blue-700">You'll be served at</Text>
        <Text className="text-2xl font-bold text-blue-900">{formatTime(appointment.scheduled_at)}</Text>
        {appointment.people_ahead !== null && (
          <Text className="text-sm text-blue-800">{peopleAheadText(appointment.people_ahead)}</Text>
        )}
        <Text className="mt-1 text-xs text-blue-700">
          {appointment.goes_through_queue
            ? 'On the day, check in from the Home tab when you arrive — you are called at your booked time.'
            : 'No queue number needed: go to the clinic by your time.'}
        </Text>
      </View>
    );
  }

  if (appointment.cancel_reason) {
    return <Text className="mt-1 text-xs text-slate-400">Cancelled: {appointment.cancel_reason}</Text>;
  }

  return null;
}

function AppointmentCard({
  appointment,
  isPast,
  onCancel,
  isCancelling,
}: {
  appointment: Appointment;
  isPast: boolean;
  onCancel?: (id: number) => void;
  isCancelling: boolean;
}) {
  // Once checked in, the patient is in the nurse's queue; leaving is hers to settle.
  const canCancel = !isPast && onCancel && ['pending', 'booked'].includes(appointment.status);
  const expired = isPast && appointment.status === 'pending';

  return (
    <View className="mb-3 rounded-xl border border-slate-200 bg-white p-4">
      <View className="flex-row items-start justify-between gap-3">
        <View className="flex-1">
          <Text className="text-sm font-semibold text-slate-900">{providerLine(appointment.provider)}</Text>
          <Text className="mt-0.5 text-sm text-slate-600">
            {formatShortDay(appointment.scheduled_at)} · {formatTime(appointment.scheduled_at)}
          </Text>
        </View>
        <Text className={`rounded-full px-2.5 py-1 text-xs font-medium ${expired ? 'bg-gray-100 text-gray-500' : STATUS_STYLES[appointment.status]}`}>
          {expired ? 'Not reviewed' : STATUS_LABELS[appointment.status]}
        </Text>
      </View>
      <Text className="mt-2 text-sm text-slate-600">{appointment.reason}</Text>
      <StatusNote appointment={appointment} isPast={isPast} />
      {canCancel && (
        <Pressable
          disabled={isCancelling}
          onPress={() => onCancel!(appointment.id)}
          className="mt-3 items-center rounded-lg border border-red-200 py-2 disabled:opacity-50"
        >
          <Text className="text-sm font-semibold text-red-600">{isCancelling ? 'Cancelling…' : 'Cancel booking'}</Text>
        </Pressable>
      )}
    </View>
  );
}

export default function MyAppointments() {
  const insets = useSafeAreaInsets();
  const { user } = useAuth();

  const [upcoming, setUpcoming] = useState<Appointment[]>([]);
  const [past, setPast] = useState<Appointment[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [cancellingId, setCancellingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [offline, setOffline] = useState<{ since: string | null } | null>(null);

  const load = useCallback(async () => {
    try {
      const { data, offlineSince } = await fetchWithCache('appointments-mine', async () => {
        const response = await api.get<{ upcoming: Appointment[]; past: Appointment[] }>('/appointments/mine');
        return response.data;
      });
      setUpcoming(data.upcoming);
      setPast(data.past);
      setOffline(offlineSince ? { since: offlineSince } : null);
    } catch (err) {
      if (isNetworkError(err)) setOffline({ since: null });
    }
  }, []);

  // Reloaded whenever the tab is opened (tabs stay mounted), and live while
  // it is open: the nurse's confirmations arrive over the socket.
  useFocusEffect(
    useCallback(() => {
      load().finally(() => setIsLoading(false));
    }, [load]),
  );

  useAppointmentUpdates(user?.id, load);

  async function handleRefresh() {
    setIsRefreshing(true);
    await load();
    setIsRefreshing(false);
  }

  function confirmCancel(id: number) {
    Alert.alert('Cancel booking?', 'This frees the time for other patients.', [
      { text: 'Keep it', style: 'cancel' },
      { text: 'Cancel booking', style: 'destructive', onPress: () => handleCancel(id) },
    ]);
  }

  async function handleCancel(id: number) {
    setError(null);
    setCancellingId(id);
    try {
      await api.post(`/appointments/${id}/cancel`);
      await load();
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to cancel this booking.'));
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
        <ScreenHeader title="Appointments" subtitle="Book Nurse Portia, the dentist or the school physician" />

        {offline && <OfflineNotice since={offline.since} />}

        {error && <Text className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>}

        <Pressable onPress={() => router.push('/book-appointment')} className="mt-6 items-center rounded-lg bg-brand py-3.5">
          <Text className="text-base font-semibold text-white">Book a new appointment</Text>
        </Pressable>

        <Text className="mb-3 mt-8 text-sm font-semibold uppercase tracking-wide text-slate-500">Upcoming</Text>
        {upcoming.length === 0 && <Text className="text-sm text-slate-400">No upcoming appointments.</Text>}
        {upcoming.map((appointment) => (
          <AppointmentCard
            key={appointment.id}
            appointment={appointment}
            isPast={false}
            onCancel={confirmCancel}
            isCancelling={cancellingId === appointment.id}
          />
        ))}

        <Text className="mb-3 mt-6 text-sm font-semibold uppercase tracking-wide text-slate-500">Past</Text>
        {past.length === 0 && <Text className="text-sm text-slate-400">No past appointments.</Text>}
        {past.map((appointment) => (
          <AppointmentCard key={appointment.id} appointment={appointment} isPast isCancelling={false} />
        ))}
      </View>
    </ScrollView>
  );
}
