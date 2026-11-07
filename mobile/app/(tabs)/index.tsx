import Ionicons from '@expo/vector-icons/Ionicons';
import { Link, router, useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  ScrollView,
  Vibration,
  View,
} from 'react-native';
import { Text } from '../../components/Text';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { AccountMenu, type MenuItem } from '../../components/AccountMenu';
import { OfflineNotice } from '../../components/OfflineNotice';
import { ScreenHeader } from '../../components/ScreenHeader';
import { hasCompleteHealthRecord, useAuth } from '../../context/AuthContext';
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
import { getEcho } from '../../lib/echo';
import { extractErrorMessage } from '../../lib/errors';
import { registerForQueuePushNotifications } from '../../lib/notifications';
import { useAppointmentUpdates } from '../../lib/realtime';
import { formatLongDate, formatShortDay, formatTime } from '../../lib/time';

interface QueueEntry {
  id: number;
  queue_number: number;
  status: 'waiting' | 'in_consultation' | 'done' | 'skipped';
  source: 'walk_in' | 'appointment';
  booked_for: string | null;
  joined_at: string | null;
  called_at: string | null;
  completed_at: string | null;
}

interface BoardEntry {
  queue_number: number;
  status: QueueEntry['status'];
}

interface QueuePayload {
  entry: QueueEntry | null;
  position: number | null;
  people_ahead: number | null;
  estimated_wait_minutes: number | null;
  estimated_wait_low: number | null;
  estimated_wait_high: number | null;
  estimated_call_at: string | null;
}

const RESOLVED_COPY: Record<'done' | 'skipped', { title: string; subtitle: string }> = {
  done: { title: 'All done', subtitle: 'Thanks for visiting the clinic today.' },
  skipped: { title: 'You were skipped', subtitle: 'Please check in again at the counter.' },
};

function firstName(name: string | undefined) {
  return name?.split(' ')[0] ?? '';
}

/**
 * A single number implies a precision the estimate does not have, so show
 * the p10-p90 range the model actually produced and keep the point estimate
 * only when there is no range to show.
 */
function formatWaitEstimate(minutes: number | null, range: { low: number; high: number } | null) {
  if (range && range.high > range.low) {
    return `${range.low}–${range.high} min`;
  }
  if (minutes !== null) {
    return minutes === 0 ? 'Any moment' : `~${minutes} min`;
  }
  return 'Calculating…';
}

function isToday(iso: string) {
  return new Date(iso).toDateString() === new Date().toDateString();
}

/**
 * Today's confirmed booking with the nurse, before the patient checks in.
 * Checking in on it keeps their place at the booked time, up to
 * LATE_GRACE_MINUTES late; after that they join the line in order.
 */
function BookingCheckInCard({
  appointment,
  isCheckingIn,
  onCheckIn,
  onWalkIn,
}: {
  appointment: Appointment;
  isCheckingIn: boolean;
  onCheckIn: () => void;
  onWalkIn: () => void;
}) {
  const late = minutesPastBooking(appointment);
  const note =
    late < 0
      ? "Check in when you arrive and you'll be called at your booked time."
      : late <= LATE_GRACE_MINUTES
        ? 'Your time is now — check in to keep your place in line.'
        : "Your booked time has passed. You can still check in; you'll join the line in order.";

  return (
    <View className="mt-8">
      <View className="rounded-2xl border border-blue-200 bg-blue-50 p-6">
        <Text className="text-xs font-medium uppercase tracking-wide text-blue-700">
          Booked with {appointment.provider?.display_name ?? 'the nurse'} today
        </Text>
        <Text className="mt-1 text-3xl font-bold text-blue-900">{formatTime(appointment.scheduled_at)}</Text>
        {late < 0 && appointment.people_ahead !== null && (
          <Text className="text-sm text-blue-800">{peopleAheadText(appointment.people_ahead)}</Text>
        )}
        <Text className="mt-2 text-sm text-blue-800">{note}</Text>
        <Pressable
          onPress={onCheckIn}
          disabled={isCheckingIn}
          className="mt-4 items-center rounded-lg bg-blue-600 py-3.5 disabled:opacity-50"
        >
          {isCheckingIn ? (
            <ActivityIndicator color="white" />
          ) : (
            <Text className="text-base font-semibold text-white">Check in for my appointment</Text>
          )}
        </Pressable>
      </View>
      <Pressable onPress={onWalkIn} disabled={isCheckingIn} hitSlop={8} className="mt-3 items-center py-1">
        <Text className="text-sm text-slate-500">
          Here for something else? <Text className="font-semibold text-slate-700">Check in as a walk-in</Text>
        </Text>
      </Pressable>
    </View>
  );
}

export default function Home() {
  const { user, logout } = useAuth();
  const [accountMenuOpen, setAccountMenuOpen] = useState(false);
  const insets = useSafeAreaInsets();

  const [isLoading, setIsLoading] = useState(true);
  const [entry, setEntry] = useState<QueueEntry | null>(null);
  // null = online; { since } = showing a cached copy (since null = nothing cached).
  const [offline, setOffline] = useState<{ since: string | null } | null>(null);
  const [position, setPosition] = useState<number | null>(null);
  const [peopleAhead, setPeopleAhead] = useState<number | null>(null);
  const [estimatedWaitMinutes, setEstimatedWaitMinutes] = useState<number | null>(null);
  const [waitRange, setWaitRange] = useState<{ low: number; high: number } | null>(null);
  const [estimatedCallAt, setEstimatedCallAt] = useState<string | null>(null);
  const [nowServing, setNowServing] = useState<number | null>(null);
  const [upcoming, setUpcoming] = useState<Appointment[]>([]);
  const [isCheckingIn, setIsCheckingIn] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const subscribedRef = useRef(false);

  // Re-render every minute: "your time is now" and "your time has passed"
  // are read off the clock, and nothing else changes at that moment.
  const [, setMinuteTick] = useState(0);
  useEffect(() => {
    const id = setInterval(() => setMinuteTick((tick) => tick + 1), 60_000);
    return () => clearInterval(id);
  }, []);

  const applyQueue = useCallback((data: QueuePayload) => {
    setEntry(data.entry);
    setPosition(data.position ?? null);
    setPeopleAhead(data.people_ahead ?? null);
    setEstimatedWaitMinutes(data.estimated_wait_minutes ?? null);
    setWaitRange(
      data.estimated_wait_low != null && data.estimated_wait_high != null
        ? { low: data.estimated_wait_low, high: data.estimated_wait_high }
        : null,
    );
    setEstimatedCallAt(data.estimated_call_at ?? null);
  }, []);

  const loadMine = useCallback(async () => {
    try {
      const { data, offlineSince } = await fetchWithCache('queue-mine', async () => {
        const response = await api.get<QueuePayload>('/queue/mine');
        return response.data;
      });
      applyQueue(data);
      setOffline(offlineSince ? { since: offlineSince } : null);
    } catch (err) {
      // No signal and nothing cached: leave whatever is on screen and say so.
      // A 401 is handled by the api interceptor; anything else is transient.
      if (isNetworkError(err)) setOffline({ since: null });
    }
  }, [applyQueue]);

  const loadAppointments = useCallback(async () => {
    try {
      const { data } = await fetchWithCache('appointments-upcoming', async () => {
        const response = await api.get<{ upcoming: Appointment[] }>('/appointments/mine');
        return response.data.upcoming.filter((a) => a.status === 'booked' || a.status === 'pending');
      });
      setUpcoming(data);
    } catch {
      setUpcoming([]);
    }
  }, []);

  // "Now serving" comes from the public board (numbers only, no auth needed).
  const loadBoard = useCallback(async () => {
    try {
      const response = await api.get<{ date: string; entries: BoardEntry[] }>('/queue-board/today');
      setNowServing(currentlyServing(response.data.entries));
      return response.data.date;
    } catch {
      return null;
    }
  }, []);

  useEffect(() => {
    registerForQueuePushNotifications();
  }, []);

  // Every time Home is shown, not just the first: tabs stay mounted, so a
  // booking just made (or a visit just finished) would otherwise not show
  // here until a pull to refresh.
  useFocusEffect(
    useCallback(() => {
      Promise.all([loadMine(), loadAppointments(), loadBoard()]).finally(() => setIsLoading(false));
    }, [loadMine, loadAppointments, loadBoard]),
  );

  useEffect(() => {
    if (!user || subscribedRef.current) return;
    subscribedRef.current = true;

    (async () => {
      const [echo, date] = await Promise.all([getEcho(), loadBoard()]);

      const personal = echo.private(`App.Models.User.${user.id}`);
      personal.listen('.queue.entry.updated', (payload: QueuePayload) => applyQueue(payload));
      // Fires only at the exact moment this patient is called — a
      // stronger, distinct cue on top of the generic entry update above.
      personal.listen('.ticket.called', () => Vibration.vibrate([0, 300, 150, 300]));

      if (date) {
        echo
          .channel(`queue-board.${date}`)
          .listen('.queue.board.updated', (payload: { entries: BoardEntry[] }) => {
            setNowServing(currentlyServing(payload.entries));
          });
      }
    })();

    return () => {
      subscribedRef.current = false;
    };
  }, [user, applyQueue, loadBoard]);

  // The nurse confirmed or declined a booking, or the line ahead moved.
  useAppointmentUpdates(user?.id, loadAppointments);

  async function handleRefresh() {
    await Promise.all([loadMine(), loadAppointments(), loadBoard()]);
  }

  async function handleCheckIn(appointmentId?: number) {
    setError(null);
    setIsCheckingIn(true);

    try {
      const response = await api.post<QueueEntry>('/queue/check-in', {
        appointment_id: appointmentId ?? null,
      });
      setEntry(response.data);
      // The socket delivers the real position moments later.
      setPosition(null);
      setPeopleAhead(null);
      setEstimatedWaitMinutes(null);
      setEstimatedCallAt(null);
      void loadAppointments();
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to check in.'));
    } finally {
      setIsCheckingIn(false);
    }
  }

  const accountMenuItems: MenuItem[] = [
    { label: 'My health record', onPress: () => router.push('/health-record') },
    { label: 'Change password', onPress: () => router.push('/change-password') },
    {
      label: 'Log out',
      destructive: true,
      onPress: async () => {
        await logout();
        router.replace('/login');
      },
    },
  ];

  if (isLoading) {
    return (
      <View className="flex-1 items-center justify-center bg-white">
        <ActivityIndicator />
      </View>
    );
  }

  const confirmedToday = upcoming.filter((a) => a.status === 'booked' && isToday(a.scheduled_at));
  const queueBookingToday = confirmedToday.find((a) => a.goes_through_queue) ?? null;
  const seenAtTimeToday = confirmedToday.filter((a) => !a.goes_through_queue);
  const nextAppointment = upcoming.find((a) => !isToday(a.scheduled_at) || a.status === 'pending') ?? null;
  const isWaiting = entry?.status === 'waiting';
  const isCalled = entry?.status === 'in_consultation';
  const isResolved = entry?.status === 'done' || entry?.status === 'skipped';
  const recordIncomplete = user !== null && !hasCompleteHealthRecord(user);

  return (
    <ScrollView
      className="flex-1 bg-white"
      contentContainerStyle={{ paddingTop: insets.top + 24, paddingBottom: 48 }}
      refreshControl={<RefreshControl refreshing={false} onRefresh={handleRefresh} />}
    >
      <View className="px-6">
        <ScreenHeader
          title={`Hi, ${firstName(user?.first_name ?? user?.name)}`}
          subtitle={formatLongDate(new Date().toISOString())}
          right={
            <Pressable onPress={() => setAccountMenuOpen(true)} hitSlop={8} accessibilityLabel="Account">
              <Ionicons name="person-circle-outline" size={30} color="#64748b" />
            </Pressable>
          }
        />

        <AccountMenu
          visible={accountMenuOpen}
          title={user?.name ?? 'Account'}
          subtitle={user?.email}
          items={accountMenuItems}
          onClose={() => setAccountMenuOpen(false)}
        />

        {offline && <OfflineNotice since={offline.since} />}

        {recordIncomplete && (
          <Pressable onPress={() => router.push('/health-record')} className="mt-4 flex-row items-center gap-2 rounded-lg bg-amber-50 px-3 py-2.5">
            <Ionicons name="alert-circle-outline" size={18} color="#b45309" />
            <Text className="flex-1 text-xs text-amber-900">Complete your health record so you can book appointments.</Text>
            <Ionicons name="chevron-forward" size={16} color="#b45309" />
          </Pressable>
        )}

        {error && <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>}

        {/* STATE A — not in the queue. A booking with the nurse today comes first. */}
        {!entry && queueBookingToday && (
          <BookingCheckInCard
            appointment={queueBookingToday}
            isCheckingIn={isCheckingIn}
            onCheckIn={() => handleCheckIn(queueBookingToday.id)}
            onWalkIn={() => handleCheckIn()}
          />
        )}

        {!entry && !queueBookingToday && (
          <View className="mt-8 rounded-2xl border border-slate-200 bg-slate-50 p-6">
            <Text className="text-lg font-semibold text-slate-900">You're not in line yet</Text>
            <Text className="mt-1 text-sm text-slate-500">
              Check in when you arrive at the clinic to get a queue number.
            </Text>
            <Pressable
              onPress={() => handleCheckIn()}
              disabled={isCheckingIn}
              className="mt-5 items-center rounded-lg bg-brand py-3.5 disabled:opacity-50"
            >
              {isCheckingIn ? (
                <ActivityIndicator color="white" />
              ) : (
                <Text className="text-base font-semibold text-white">Check in as walk-in</Text>
              )}
            </Pressable>
          </View>
        )}

        {/* The dentist's and physician's patients come at their time, no queue number. */}
        {seenAtTimeToday.map((appointment) => {
          const timeHasCome = minutesPastBooking(appointment) >= 0;
          return (
            <View key={appointment.id} className="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-6">
              <Text className="text-xs font-medium uppercase tracking-wide text-emerald-700">Today · {providerLine(appointment.provider)}</Text>
              <Text className="mt-1 text-sm text-emerald-800">{timeHasCome ? 'Your booked time' : "You'll be served at"}</Text>
              <Text className="text-3xl font-bold text-emerald-900">{formatTime(appointment.scheduled_at)}</Text>
              {appointment.people_ahead !== null && (
                <Text className="mt-1 text-base font-medium text-emerald-900">{peopleAheadText(appointment.people_ahead)}</Text>
              )}
              <Text className="mt-2 text-xs text-emerald-700">
                {timeHasCome
                  ? "If you haven't been called in yet, let the clinic staff know you're here."
                  : 'No queue number needed — please be at the clinic by your time.'}
              </Text>
            </View>
          );
        })}

        {/* STATE B — waiting */}
        {entry && isWaiting && (
          <View className="mt-8">
            <View className="items-center rounded-2xl border border-slate-200 bg-slate-50 p-8">
              <Text className="text-sm font-medium uppercase tracking-wide text-slate-500">Your number</Text>
              <Text className="mt-2 text-7xl font-bold text-slate-900">{entry.queue_number}</Text>
              {entry.booked_for && (
                <Text className="mt-2 rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800">
                  Booked for {formatTime(entry.booked_for)} — called at your booked time
                </Text>
              )}
              {peopleAhead !== null && (
                <>
                  <Text className="mt-6 text-3xl font-bold text-blue-600">
                    {peopleAhead === 0 ? "You're next" : peopleAhead}
                  </Text>
                  <Text className="text-sm text-slate-500">
                    {peopleAhead === 0 ? 'Please stay close by' : peopleAhead === 1 ? 'person ahead of you' : 'people ahead of you'}
                  </Text>

                  <View className="mt-6 w-full items-center rounded-xl bg-white px-4 py-4">
                    <Text className="text-xs font-medium uppercase tracking-wide text-slate-500">Estimated wait</Text>
                    <Text className="mt-1 text-3xl font-bold text-slate-900">{formatWaitEstimate(estimatedWaitMinutes, waitRange)}</Text>
                    {estimatedCallAt && peopleAhead > 0 && (
                      <Text className="mt-1 text-base text-slate-700">
                        Likely called around <Text className="font-semibold">{formatTime(estimatedCallAt)}</Text>
                      </Text>
                    )}
                    {position !== null && <Text className="mt-1 text-xs text-slate-400">Position #{position}</Text>}
                  </View>
                  {waitRange !== null && (
                    <Text className="mt-2 text-center text-xs text-slate-400">
                      Predicted from the current queue, today's pace and bookings due soon
                    </Text>
                  )}
                </>
              )}
            </View>
            {nowServing !== null && (
              <Text className="mt-3 text-center text-sm text-slate-500">
                Now serving <Text className="font-semibold text-slate-900">#{nowServing}</Text>
              </Text>
            )}
          </View>
        )}

        {/* STATE C — called */}
        {entry && isCalled && (
          <View className="mt-8 items-center rounded-2xl bg-blue-600 p-8">
            <Text className="text-sm font-medium uppercase tracking-wide text-blue-100">Your number</Text>
            <Text className="mt-2 text-7xl font-bold text-white">{entry.queue_number}</Text>
            <Text className="mt-6 text-xl font-semibold text-white">It's your turn</Text>
            <Text className="mt-1 text-center text-sm text-blue-100">Please proceed to the clinic counter now.</Text>
          </View>
        )}

        {/* STATE D — done / skipped */}
        {entry && isResolved && (
          <View className="mt-8">
            <View className="items-center rounded-2xl border border-slate-200 bg-slate-50 p-8">
              <Text className="text-lg font-semibold text-slate-900">
                {RESOLVED_COPY[entry.status as 'done' | 'skipped'].title}
              </Text>
              <Text className="mt-1 text-center text-sm text-slate-500">
                {RESOLVED_COPY[entry.status as 'done' | 'skipped'].subtitle}
              </Text>
            </View>
            <Pressable onPress={() => setEntry(null)} className="mt-4 items-center rounded-lg border border-slate-300 py-3">
              <Text className="font-semibold text-slate-700">Back to check-in</Text>
            </Pressable>
          </View>
        )}

        <Text className="mb-2 mt-10 text-sm font-semibold uppercase tracking-wide text-slate-500">Next appointment</Text>
        {nextAppointment ? (
          <Link href="/(tabs)/appointments" asChild>
            <Pressable className="flex-row items-center justify-between rounded-xl border border-slate-200 p-4">
              <View className="flex-1">
                <Text className="text-sm font-medium text-slate-900">
                  {formatShortDay(nextAppointment.scheduled_at)} · {formatTime(nextAppointment.scheduled_at)}
                </Text>
                <Text className="mt-0.5 text-xs text-slate-500">{providerLine(nextAppointment.provider)}</Text>
              </View>
              <Text className={`mr-2 rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[nextAppointment.status]}`}>
                {STATUS_LABELS[nextAppointment.status]}
              </Text>
              <Ionicons name="chevron-forward" size={18} color="#94a3b8" />
            </Pressable>
          </Link>
        ) : (
          <Text className="text-sm text-slate-400">No upcoming appointments.</Text>
        )}
        <Link href="/book-appointment" asChild>
          <Pressable className="mt-3">
            <Text className="text-sm font-semibold text-blue-600">Book an appointment →</Text>
          </Pressable>
        </Link>
      </View>
    </ScrollView>
  );
}

function currentlyServing(entries: BoardEntry[]) {
  const serving = entries.filter((e) => e.status === 'in_consultation');
  return serving.length ? Math.max(...serving.map((e) => e.queue_number)) : null;
}
