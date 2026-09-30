import { router } from 'expo-router';
import { useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  ScrollView,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { api } from '../lib/api';
import { extractErrorMessage } from '../lib/errors';

interface ClinicSchedule {
  day_of_week: number;
}

interface Slot {
  time: string;
  datetime: string;
  capacity: number;
  booked: number;
  available: boolean;
}

interface SlotsResponse {
  date: string;
  is_event_day: boolean;
  event?: { label: string } | null;
  slots: Slot[];
}

function dateKey(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function nextDays(count: number) {
  const days: Date[] = [];
  const start = new Date();
  for (let i = 0; i < count; i++) {
    const d = new Date(start);
    d.setDate(start.getDate() + i);
    days.push(d);
  }
  return days;
}

const WEEKDAY_LABEL = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function BookAppointment() {
  const insets = useSafeAreaInsets();

  const [openWeekdays, setOpenWeekdays] = useState<Set<number> | null>(null);
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [slotsResponse, setSlotsResponse] = useState<SlotsResponse | null>(null);
  const [isLoadingSlots, setIsLoadingSlots] = useState(false);
  const [selectedTime, setSelectedTime] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const days = useMemo(() => nextDays(14), []);

  useEffect(() => {
    api
      .get<ClinicSchedule[]>('/schedule')
      .then((response) => {
        setOpenWeekdays(new Set(response.data.map((s) => s.day_of_week)));
      })
      .catch(() => setOpenWeekdays(new Set()));
  }, []);

  async function handleSelectDate(date: Date) {
    const key = dateKey(date);
    setSelectedDate(key);
    setSelectedTime(null);
    setSlotsResponse(null);
    setIsLoadingSlots(true);
    setError(null);

    try {
      const response = await api.get<SlotsResponse>('/appointments/available-slots', {
        params: { date: key },
      });
      setSlotsResponse(response.data);
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to load available times.'));
    } finally {
      setIsLoadingSlots(false);
    }
  }

  async function handleConfirm() {
    if (!selectedDate || !selectedTime || !reason.trim()) return;

    setIsSubmitting(true);
    setError(null);

    try {
      await api.post('/appointments', {
        date: selectedDate,
        time: selectedTime,
        reason: reason.trim(),
      });
      setSuccess(true);
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to book this appointment.'));
      if (selectedDate) {
        const d = new Date(selectedDate);
        handleSelectDate(d);
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  if (success) {
    return (
      <View
        className="flex-1 items-center justify-center bg-white px-6"
        style={{ paddingTop: insets.top }}
      >
        <Text className="text-2xl font-bold text-slate-900">Appointment booked</Text>
        <Text className="mt-2 text-center text-sm text-slate-500">
          We'll see you at your scheduled time.
        </Text>
        <Pressable
          onPress={() => router.replace('/(tabs)/appointments')}
          className="mt-8 items-center rounded-lg bg-brand px-6 py-3.5"
        >
          <Text className="text-base font-semibold text-white">View my appointments</Text>
        </Pressable>
        <Pressable onPress={() => router.back()} className="mt-4 items-center">
          <Text className="text-sm text-slate-400">Back to home</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <ScrollView
      className="flex-1 bg-white"
      contentContainerStyle={{ paddingTop: insets.top + 24, paddingBottom: 48 }}
    >
      <View className="px-6">
        <View className="flex-row items-center justify-between">
          <Text className="text-2xl font-bold text-slate-900">Book Appointment</Text>
          <Pressable onPress={() => router.back()}>
            <Text className="text-sm text-slate-400">Close</Text>
          </Pressable>
        </View>

        {error && (
          <Text className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>
        )}

        <Text className="mt-6 text-sm font-semibold text-slate-700">Select a date</Text>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} className="mt-2">
          <View className="flex-row gap-2">
            {days.map((d) => {
              const key = dateKey(d);
              const isOpen = openWeekdays === null || openWeekdays.has(d.getDay());
              const isSelected = selectedDate === key;
              return (
                <Pressable
                  key={key}
                  disabled={!isOpen}
                  onPress={() => handleSelectDate(d)}
                  className={`w-16 items-center rounded-xl border py-3 ${
                    isSelected
                      ? 'border-brand bg-brand'
                      : isOpen
                        ? 'border-slate-200 bg-white'
                        : 'border-slate-100 bg-slate-50'
                  }`}
                >
                  <Text className={`text-xs ${isSelected ? 'text-white' : isOpen ? 'text-slate-500' : 'text-slate-300'}`}>
                    {WEEKDAY_LABEL[d.getDay()]}
                  </Text>
                  <Text
                    className={`mt-1 text-base font-semibold ${isSelected ? 'text-white' : isOpen ? 'text-slate-900' : 'text-slate-300'}`}
                  >
                    {d.getDate()}
                  </Text>
                </Pressable>
              );
            })}
          </View>
        </ScrollView>

        {selectedDate && (
          <View className="mt-6">
            <Text className="text-sm font-semibold text-slate-700">Select a time</Text>

            {isLoadingSlots && (
              <ActivityIndicator className="mt-4" />
            )}

            {!isLoadingSlots && slotsResponse?.is_event_day && (
              <Text className="mt-3 text-sm text-slate-400">
                The clinic is closed on this date{slotsResponse.event ? ` (${slotsResponse.event.label})` : ''}.
              </Text>
            )}

            {!isLoadingSlots && slotsResponse && !slotsResponse.is_event_day && slotsResponse.slots.length === 0 && (
              <Text className="mt-3 text-sm text-slate-400">No slots available on this date.</Text>
            )}

            {!isLoadingSlots && slotsResponse && slotsResponse.slots.length > 0 && (
              <View className="mt-3 flex-row flex-wrap gap-2">
                {slotsResponse.slots.map((slot) => {
                  const isSelected = selectedTime === slot.time;
                  return (
                    <Pressable
                      key={slot.time}
                      disabled={!slot.available}
                      onPress={() => setSelectedTime(slot.time)}
                      className={`rounded-lg border px-4 py-2 ${
                        isSelected
                          ? 'border-brand bg-brand'
                          : slot.available
                            ? 'border-slate-200 bg-white'
                            : 'border-slate-100 bg-slate-50'
                      }`}
                    >
                      <Text
                        className={`text-sm font-medium ${
                          isSelected ? 'text-white' : slot.available ? 'text-slate-900' : 'text-slate-300'
                        }`}
                      >
                        {slot.time}
                      </Text>
                    </Pressable>
                  );
                })}
              </View>
            )}
          </View>
        )}

        {selectedTime && (
          <View className="mt-6">
            <Text className="text-sm font-semibold text-slate-700">Reason for visit</Text>
            <TextInput
              value={reason}
              onChangeText={setReason}
              multiline
              numberOfLines={3}
              placeholder="e.g. Fever, follow-up checkup"
              className="mt-2 rounded-lg border border-slate-200 px-4 py-3 text-sm text-slate-900"
              style={{ minHeight: 80, textAlignVertical: 'top' }}
            />

            <Pressable
              onPress={handleConfirm}
              disabled={isSubmitting || !reason.trim()}
              className="mt-6 items-center rounded-lg bg-brand py-3.5 disabled:opacity-50"
            >
              {isSubmitting ? (
                <ActivityIndicator color="white" />
              ) : (
                <Text className="text-base font-semibold text-white">Confirm appointment</Text>
              )}
            </Pressable>
          </View>
        )}
      </View>
    </ScrollView>
  );
}
