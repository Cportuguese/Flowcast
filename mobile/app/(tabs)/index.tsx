import Ionicons from '@expo/vector-icons/Ionicons';
import { Link, router } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  RefreshControl,
  ScrollView,
  Text,
  Vibration,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { OfflineNotice } from '../../components/OfflineNotice';
import { ScreenHeader } from '../../components/ScreenHeader';
import { useAuth } from '../../context/AuthContext';
import { api } from '../../lib/api';
import { fetchWithCache, isNetworkError } from '../../lib/cache';
import { getEcho } from '../../lib/echo';
import { extractErrorMessage } from '../../lib/errors';
import { registerForQueuePushNotifications } from '../../lib/notifications';

interface QueueEntry {
  id: number;
  queue_number: number;
  status: 'waiting' | 'in_consultation' | 'done' | 'skipped';
  source: 'walk_in' | 'appointment';
  joined_at: string | null;
  called_at: string | null;
  completed_at: string | null;
}

interface Appointment {
  id: number;
  scheduled_at: string;
  status: string;
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
}

const RESOLVED_COPY: Record<'done' | 'skipped', { title: string; subtitle: string }> = {
  done: { title: 'All done', subtitle: 'Thanks for visiting the clinic today.' },
  skipped: { title: 'You were skipped', subtitle: 'Please check in again at the counter.' },
};

function firstName(name: string | undefined) {
  return name?.split(' ')[0] ?? '';
}

function formatTime(iso: string) {
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatDay(iso: string) {
  return new Date(iso).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
}

/**
 * A single number implies a precision the estimate does not have, so show
 * the p10-p90 range the model actually produced and keep the point estimate
 * only when there is no range to show.
 */
function formatWaitEstimate(minutes: number | null, range: { low: number; high: number } | null) {
  if (range && range.high > range.low) {
    return `~${range.low}–${range.high} min`;
  }
  if (minutes !== null) {
    return `~${minutes} min`;
  }
  return 'Calculating wait…';
}

function isToday(iso: string) {
  return new Date(iso).toDateString() === new Date().toDateString();
}

export default function Home() {
  const { user, logout } = useAuth();
  const insets = useSafeAreaInsets();

  const [isLoading, setIsLoading] = useState(true);
  const [entry, setEntry] = useState<QueueEntry | null>(null);
  // null = online; { since } = showing a cached copy (since null = nothing cached).
  const [offline, setOffline] = useState<{ since: string | null } | null>(null);
  const [position, setPosition] = useState<number | null>(null);
  const [peopleAhead, setPeopleAhead] = useState<number | null>(null);
  const [estimatedWaitMinutes, setEstimatedWaitMinutes] = useState<number | null>(null);
  const [waitRange, setWaitRange] = useState<{ low: number; high: number } | null>(null);
  const [nowServing, setNowServing] = useState<number | null>(null);
  const [upcoming, setUpcoming] = useState<Appointment[]>([]);
  const [isCheckingIn, setIsCheckingIn] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const subscribedRef = useRef(false);

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
        return response.data.upcoming.filter((a) => a.status === 'booked');
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
    (async () => {
      setIsLoading(true);
      await Promise.all([loadMine(), loadAppointments(), loadBoard()]);
      setIsLoading(false);
    })();

    registerForQueuePushNotifications();
  }, [loadMine, loadAppointments, loadBoard]);

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
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to check in.'));
    } finally {
      setIsCheckingIn(false);
    }
  }

  function openAccountMenu() {
    Alert.alert(user?.name ?? 'Account', user?.email, [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Change password', onPress: () => router.push('/change-password') },
      {
        text: 'Log out',
        style: 'destructive',
        onPress: async () => {
          await logout();
          router.replace('/login');
        },
      },
    ]);
  }

  if (isLoading) {
    return (
      <View className="flex-1 items-center justify-center bg-white">
        <ActivityIndicator />
      </View>
    );
  }

  const todaysAppointment = upcoming.find((a) => isToday(a.scheduled_at)) ?? null;
  const nextAppointment = upcoming.find((a) => !isToday(a.scheduled_at)) ?? null;
  const isWaiting = entry?.status === 'waiting';
  const isCalled = entry?.status === 'in_consultation';
  const isResolved = entry?.status === 'done' || entry?.status === 'skipped';

  return (
    <ScrollView
      className="flex-1 bg-white"
      contentContainerStyle={{ paddingTop: insets.top + 24, paddingBottom: 48 }}
      refreshControl={<RefreshControl refreshing={false} onRefresh={handleRefresh} />}
    >
      <View className="px-6">
        <ScreenHeader
          title={`Hi, ${firstName(user?.name)}`}
          subtitle={new Date().toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' })}
          right={
            <Pressable onPress={openAccountMenu} hitSlop={8} accessibilityLabel="Account">
              <Ionicons name="person-circle-outline" size={30} color="#64748b" />
            </Pressable>
          }
        />

        {offline && <OfflineNotice since={offline.since} />}

        {error && (
          <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>
        )}

        {/* STATE A — not in the queue */}
        {!entry && (
          <View className="mt-8">
            <View className="rounded-2xl border border-slate-200 bg-slate-50 p-6">
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

            {todaysAppointment && (
              <View className="mt-4 rounded-2xl border border-blue-200 bg-blue-50 p-6">
                <Text className="text-sm font-semibold text-blue-900">
                  Appointment today at {formatTime(todaysAppointment.scheduled_at)}
                </Text>
                <Pressable
                  onPress={() => handleCheckIn(todaysAppointment.id)}
                  disabled={isCheckingIn}
                  className="mt-3 items-center rounded-lg bg-blue-600 py-3 disabled:opacity-50"
                >
                  <Text className="font-semibold text-white">Check in for my appointment</Text>
                </Pressable>
              </View>
            )}
          </View>
        )}

        {/* STATE B — waiting */}
        {entry && isWaiting && (
          <View className="mt-8">
            <View className="items-center rounded-2xl border border-slate-200 bg-slate-50 p-8">
              <Text className="text-sm font-medium uppercase tracking-wide text-slate-500">Your number</Text>
              <Text className="mt-2 text-7xl font-bold text-slate-900">{entry.queue_number}</Text>
              {peopleAhead !== null && (
                <>
                  <Text className="mt-6 text-3xl font-bold text-blue-600">
                    {peopleAhead === 0 ? "You're next" : peopleAhead}
                  </Text>
                  <Text className="text-sm text-slate-500">
                    {peopleAhead === 0 ? 'Please stay close by' : `people ahead of you`}
                  </Text>
                  <Text className="mt-2 text-xs text-slate-400">
                    {formatWaitEstimate(estimatedWaitMinutes, waitRange)}
                    {position !== null && ` · Position #${position}`}
                  </Text>
                  {waitRange !== null && (
                    <Text className="mt-1 text-center text-xs text-slate-400">
                      based on the current queue and today's pace
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
            <Pressable
              onPress={() => setEntry(null)}
              className="mt-4 items-center rounded-lg border border-slate-300 py-3"
            >
              <Text className="font-semibold text-slate-700">Back to check-in</Text>
            </Pressable>
          </View>
        )}

        <Text className="mb-2 mt-10 text-sm font-semibold uppercase tracking-wide text-slate-500">
          Next appointment
        </Text>
        {nextAppointment ? (
          <Link href="/(tabs)/appointments" asChild>
            <Pressable className="flex-row items-center justify-between rounded-xl border border-slate-200 p-4">
              <Text className="text-sm font-medium text-slate-900">
                {formatDay(nextAppointment.scheduled_at)} · {formatTime(nextAppointment.scheduled_at)}
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
