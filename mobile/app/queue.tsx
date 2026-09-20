import axios from 'axios';
import { router } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, Text, Vibration, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAuth } from '../context/AuthContext';
import { api } from '../lib/api';
import { getEcho } from '../lib/echo';
import { registerForQueuePushNotifications } from '../lib/notifications';

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

const STATUS_COPY: Record<QueueEntry['status'], { title: string; subtitle: string }> = {
  waiting: { title: "You're in line", subtitle: 'We will notify you as your turn approaches.' },
  in_consultation: { title: "You're up!", subtitle: 'Please proceed to the counter now.' },
  done: { title: 'All done', subtitle: 'Thanks for visiting the clinic today.' },
  skipped: { title: 'You were skipped', subtitle: 'Please check in again at the counter.' },
};

export default function Queue() {
  const { user, logout } = useAuth();
  const insets = useSafeAreaInsets();

  const [isLoading, setIsLoading] = useState(true);
  const [entry, setEntry] = useState<QueueEntry | null>(null);
  const [position, setPosition] = useState<number | null>(null);
  const [peopleAhead, setPeopleAhead] = useState<number | null>(null);
  const [estimatedWaitMinutes, setEstimatedWaitMinutes] = useState<number | null>(null);
  const [todaysAppointment, setTodaysAppointment] = useState<Appointment | null>(null);
  const [isCheckingIn, setIsCheckingIn] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const subscribedRef = useRef(false);

  const loadMine = useCallback(async () => {
    const response = await api.get<{
      entry: QueueEntry | null;
      position: number | null;
      people_ahead: number | null;
      estimated_wait_minutes: number | null;
    }>('/queue/mine');

    setEntry(response.data.entry);
    setPosition(response.data.position ?? null);
    setPeopleAhead(response.data.people_ahead ?? null);
    setEstimatedWaitMinutes(response.data.estimated_wait_minutes ?? null);
  }, []);

  const loadTodaysAppointment = useCallback(async () => {
    try {
      const response = await api.get<{ upcoming: Appointment[] }>('/appointments/mine');
      const today = new Date().toDateString();
      const match = response.data.upcoming.find(
        (a) => a.status === 'booked' && new Date(a.scheduled_at).toDateString() === today,
      );
      setTodaysAppointment(match ?? null);
    } catch {
      setTodaysAppointment(null);
    }
  }, []);

  useEffect(() => {
    (async () => {
      setIsLoading(true);
      await Promise.all([loadMine(), loadTodaysAppointment()]);
      setIsLoading(false);
    })();

    registerForQueuePushNotifications();
  }, [loadMine, loadTodaysAppointment]);

  useEffect(() => {
    if (!user || subscribedRef.current) return;
    subscribedRef.current = true;

    let channelName: string;

    getEcho().then((echo) => {
      channelName = `App.Models.User.${user.id}`;
      const channel = echo.private(channelName);

      channel.listen(
        '.queue.entry.updated',
        (payload: {
          entry: QueueEntry;
          position: number | null;
          people_ahead: number | null;
          estimated_wait_minutes: number | null;
        }) => {
          setEntry(payload.entry);
          setPosition(payload.position);
          setPeopleAhead(payload.people_ahead);
          setEstimatedWaitMinutes(payload.estimated_wait_minutes ?? null);
        },
      );

      // Fires only at the exact moment this patient is called — a
      // stronger, distinct cue on top of the generic entry update above.
      channel.listen('.ticket.called', () => {
        Vibration.vibrate([0, 300, 150, 300]);
      });
    });

    return () => {
      subscribedRef.current = false;
    };
  }, [user]);

  async function handleCheckIn(appointmentId?: number) {
    setError(null);
    setIsCheckingIn(true);

    try {
      const response = await api.post<QueueEntry>('/queue/check-in', {
        appointment_id: appointmentId ?? null,
      });
      setEntry(response.data);
      setPosition(1);
      setPeopleAhead(0);
    } catch (err) {
      if (axios.isAxiosError(err) && err.response) {
        const data = err.response.data as { message?: string };
        setError(data.message ?? 'Unable to check in.');
      } else {
        setError('Unable to check in.');
      }
    } finally {
      setIsCheckingIn(false);
    }
  }

  async function handleLogout() {
    await logout();
    router.replace('/login');
  }

  if (isLoading) {
    return (
      <View className="flex-1 items-center justify-center bg-white">
        <ActivityIndicator />
      </View>
    );
  }

  const isActive = entry && (entry.status === 'waiting' || entry.status === 'in_consultation');
  const isResolved = entry && (entry.status === 'done' || entry.status === 'skipped');

  return (
    <ScrollView
      className="flex-1 bg-white"
      contentContainerStyle={{ paddingTop: insets.top + 24, paddingBottom: 48 }}
      refreshControl={<RefreshControl refreshing={false} onRefresh={loadMine} />}
    >
      <View className="px-6">
        <Text className="text-2xl font-bold text-slate-900">Clinic Queue</Text>
        <Text className="mt-1 text-base text-slate-500">Welcome, {user?.name}</Text>

        <View className="mt-4 flex-row gap-3">
          <Pressable
            onPress={() => router.push('/my-appointments')}
            className="flex-1 items-center rounded-lg border border-slate-200 py-3"
          >
            <Text className="text-sm font-semibold text-slate-700">My Appointments</Text>
          </Pressable>
          <Pressable
            onPress={() => router.push('/visit-history')}
            className="flex-1 items-center rounded-lg border border-slate-200 py-3"
          >
            <Text className="text-sm font-semibold text-slate-700">Visit History</Text>
          </Pressable>
        </View>

        {error && (
          <Text className="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>
        )}

        {!entry && (
          <View className="mt-8">
            {todaysAppointment && (
              <View className="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
                <Text className="text-sm font-semibold text-blue-900">
                  You have an appointment today at{' '}
                  {new Date(todaysAppointment.scheduled_at).toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                  })}
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

            <Pressable
              onPress={() => handleCheckIn()}
              disabled={isCheckingIn}
              className="items-center rounded-lg bg-brand py-3.5 disabled:opacity-50"
            >
              {isCheckingIn ? (
                <ActivityIndicator color="white" />
              ) : (
                <Text className="text-base font-semibold text-white">Check in as walk-in</Text>
              )}
            </Pressable>
          </View>
        )}

        {entry && (
          <View className="mt-8 items-center rounded-2xl border border-slate-200 bg-slate-50 p-8">
            <Text className="text-sm font-medium uppercase tracking-wide text-slate-500">
              Your queue number
            </Text>
            <Text className="mt-2 text-6xl font-bold text-slate-900">{entry.queue_number}</Text>

            <Text className="mt-6 text-lg font-semibold text-slate-900">
              {STATUS_COPY[entry.status].title}
            </Text>
            <Text className="mt-1 text-center text-sm text-slate-500">
              {STATUS_COPY[entry.status].subtitle}
            </Text>

            {isActive && entry.status === 'waiting' && peopleAhead !== null && (
              <View className="mt-6 items-center">
                <Text className="text-4xl font-bold text-blue-600">{peopleAhead}</Text>
                <Text className="text-sm text-slate-500">
                  {peopleAhead === 0 ? "You're next!" : 'people ahead of you'}
                </Text>
                {position !== null && (
                  <Text className="mt-1 text-xs text-slate-400">Position #{position} in line</Text>
                )}
                {!!estimatedWaitMinutes && (
                  <Text className="mt-1 text-xs text-slate-400">
                    Estimated wait: ~{estimatedWaitMinutes} min
                  </Text>
                )}
              </View>
            )}
          </View>
        )}

        {isResolved && (
          <Pressable
            onPress={() => setEntry(null)}
            className="mt-6 items-center rounded-lg border border-slate-300 py-3"
          >
            <Text className="font-semibold text-slate-700">Back to check-in</Text>
          </Pressable>
        )}

        <Pressable onPress={handleLogout} className="mt-10 items-center">
          <Text className="text-sm text-slate-400">Log out</Text>
        </Pressable>
      </View>
    </ScrollView>
  );
}
