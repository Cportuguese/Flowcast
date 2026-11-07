import Ionicons from '@expo/vector-icons/Ionicons';
import axios from 'axios';
import { router } from 'expo-router';
import { useCallback, useEffect, useRef, useState, type ComponentRef, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, ScrollView, View, type LayoutChangeEvent } from 'react-native';
import { Text, TextInput } from '../components/Text';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { MonthCalendar, type CalendarDay } from '../components/MonthCalendar';
import { hasCompleteHealthRecord, useAuth } from '../context/AuthContext';
import { api } from '../lib/api';
import { providerLine, type Appointment, type Provider } from '../lib/appointments';
import { courseName, departmentName, useDepartments } from '../lib/departments';
import { extractErrorMessage } from '../lib/errors';
import { WEEKDAY_SHORT, formatBirthdate, formatLongDate, formatSlotTime, formatTime } from '../lib/time';

interface ScheduleRow {
  day_of_week: number;
  start_time: string;
  end_time: string;
  slot_minutes: number;
}

interface ProviderListing extends Provider {
  schedule: ScheduleRow[];
}

interface Slot {
  time: string;
  available: boolean;
}

/** The API refuses bookings further out than this. */
const BOOKING_HORIZON_DAYS = 60;

const REASON_CHIPS: Record<string, string[]> = {
  nurse: ['Blood pressure monitoring', 'Follow-up check-up', 'Wound dressing follow-up', 'Medical certificate for enrollment'],
  dentist: ['Dental check-up and cleaning', 'Toothache, for extraction', 'Tooth cavity', 'Bleeding gums', 'Broken tooth'],
  physician: ['Medical clearance', 'Persistent cough', 'Recurring headaches', 'Blood pressure follow-up', 'Skin rash'],
};

const PROFESSION_ICONS: Record<string, keyof typeof Ionicons.glyphMap> = {
  nurse: 'medkit-outline',
  dentist: 'happy-outline',
  physician: 'pulse-outline',
};

/** "Mon–Sat · 8:00 AM–6:00 PM", days with the same hours grouped together. */
function describeHours(schedule: ScheduleRow[]) {
  const groups = new Map<string, number[]>();
  for (const row of schedule) {
    const hours = `${formatSlotTime(row.start_time.slice(0, 5))}–${formatSlotTime(row.end_time.slice(0, 5))}`;
    groups.set(hours, [...(groups.get(hours) ?? []), row.day_of_week]);
  }
  return [...groups.entries()]
    .map(([hours, days]) => {
      const sorted = [...days].sort();
      const consecutive = sorted.length > 2 && sorted.every((day, i) => i === 0 || day === sorted[i - 1] + 1);
      const names = consecutive
        ? `${WEEKDAY_SHORT[sorted[0]]}–${WEEKDAY_SHORT[sorted[sorted.length - 1]]}`
        : sorted.map((day) => WEEKDAY_SHORT[day]).join(sorted.length === 2 ? ' & ' : ', ');
      return `${names} · ${hours}`;
    })
    .join('\n');
}

function monthKey(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function Section({
  step,
  title,
  children,
  onLayout,
}: {
  step: number;
  title: string;
  children: ReactNode;
  onLayout?: (event: LayoutChangeEvent) => void;
}) {
  return (
    <View className="mt-7" onLayout={onLayout}>
      <View className="flex-row items-center gap-2">
        <View className="h-6 w-6 items-center justify-center rounded-full bg-brand">
          <Text className="text-xs font-bold text-white">{step}</Text>
        </View>
        <Text className="text-base font-semibold text-slate-900">{title}</Text>
      </View>
      <View className="mt-3">{children}</View>
    </View>
  );
}

/**
 * Booking: who to see, a day on the calendar (its free times then appear),
 * the reason, a check of the patient's health record, and a code emailed
 * to them. The booking then waits for Nurse Portia's review.
 */
export default function BookAppointment() {
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const departments = useDepartments();
  const scrollRef = useRef<ComponentRef<typeof ScrollView>>(null);

  const [providers, setProviders] = useState<ProviderListing[] | null>(null);
  const [provider, setProvider] = useState<ProviderListing | null>(null);
  const [month, setMonth] = useState(() => new Date());
  const [calendar, setCalendar] = useState<Record<string, CalendarDay> | null>(null);
  const [selectedDay, setSelectedDay] = useState<CalendarDay | null>(null);
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [selectedTime, setSelectedTime] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [stage, setStage] = useState<'details' | 'code'>('details');
  const [code, setCode] = useState('');
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [resendIn, setResendIn] = useState(0);
  const [booked, setBooked] = useState<Appointment | null>(null);
  const [isBusy, setIsBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Each step appears below the last one, often under the fold on a phone:
  // the times under the calendar, the reason under the times. The step the
  // patient just unlocked is scrolled into view once it has laid out.
  const pendingReveal = useRef<'day' | 'reason' | 'record' | null>(null);
  const slotsRequest = useRef(0);
  const scrollY = useRef(0);
  const viewportHeight = useRef(0);
  const contentTop = insets.top + 24;

  function revealWhenLaidOut(step: 'day' | 'reason' | 'record') {
    return (event: LayoutChangeEvent) => {
      if (pendingReveal.current !== step) return;
      pendingReveal.current = null;

      const { y, height } = event.nativeEvent.layout;
      const top = contentTop + y;
      const bottom = top + height;

      // Scroll only as far as needed to show the whole step, never past its top.
      if (bottom > scrollY.current + viewportHeight.current) {
        scrollRef.current?.scrollTo({ y: Math.max(0, Math.min(top - 16, bottom - viewportHeight.current + 24)), animated: true });
      }
    };
  }

  const today = new Date();
  const lastBookable = new Date(today.getFullYear(), today.getMonth(), today.getDate() + BOOKING_HORIZON_DAYS);
  const canGoBack = monthKey(month) > monthKey(today);
  const canGoForward = monthKey(month) < monthKey(lastBookable);
  const recordComplete = hasCompleteHealthRecord(user);

  useEffect(() => {
    api
      .get<ProviderListing[]>('/providers')
      .then((response) => setProviders(response.data))
      .catch((err) => {
        setProviders([]);
        setError(extractErrorMessage(err, 'Unable to load the clinic professionals.'));
      });
  }, []);

  const loadCalendar = useCallback(async (forProvider: ProviderListing, forMonth: Date) => {
    setCalendar(null);
    try {
      const response = await api.get<{ days: CalendarDay[] }>('/appointments/calendar', {
        params: { provider_id: forProvider.id, month: monthKey(forMonth) },
      });
      setCalendar(Object.fromEntries(response.data.days.map((day) => [day.date, day])));
    } catch (err) {
      setCalendar({});
      setError(extractErrorMessage(err, 'Unable to load the calendar.'));
    }
  }, []);

  useEffect(() => {
    if (provider) void loadCalendar(provider, month);
  }, [provider, month, loadCalendar]);

  // Counts down the "send again" wait after a code goes out.
  useEffect(() => {
    if (resendIn <= 0) return;
    const id = setTimeout(() => setResendIn((seconds) => seconds - 1), 1000);
    return () => clearTimeout(id);
  }, [resendIn]);

  function chooseProvider(next: ProviderListing) {
    setProvider(next);
    setMonth(new Date());
    slotsRequest.current++;
    setSelectedDay(null);
    setSlots(null);
    setSelectedTime(null);
    setReason('');
    setError(null);
  }

  async function chooseDay(day: CalendarDay) {
    // Only the latest tap's times may land: tapping two days quickly must
    // not leave one day's times showing under the other day.
    const request = ++slotsRequest.current;
    setSelectedDay(day);
    setSelectedTime(null);
    setSlots(null);
    setError(null);

    if (day.status !== 'open' || !provider) {
      pendingReveal.current = 'day';
      return;
    }

    try {
      const response = await api.get<{ slots: Slot[] }>('/appointments/available-slots', {
        params: { provider_id: provider.id, date: day.date },
      });
      if (request !== slotsRequest.current) return;
      pendingReveal.current = 'day';
      setSlots(response.data.slots);
    } catch (err) {
      if (request !== slotsRequest.current) return;
      setSlots([]);
      setError(extractErrorMessage(err, 'Unable to load the times for this day.'));
    }
  }

  function shiftMonth(by: number) {
    setMonth((current) => new Date(current.getFullYear(), current.getMonth() + by, 1));
    slotsRequest.current++;
    setSelectedDay(null);
    setSlots(null);
    setSelectedTime(null);
  }

  function bookingPayload() {
    return { provider_id: provider!.id, date: selectedDay!.date, time: selectedTime!, reason: reason.trim() };
  }

  async function sendCode() {
    setError(null);
    setIsBusy(true);
    try {
      const response = await api.post<{ sent_to: string; resend_in: number }>('/appointments/otp', bookingPayload());
      setSentTo(response.data.sent_to);
      setResendIn(response.data.resend_in);
      setCode('');
      setStage('code');
      scrollRef.current?.scrollTo({ y: 0, animated: true });
    } catch (err) {
      if (axios.isAxiosError(err) && err.response?.status === 429) {
        setResendIn((err.response.data as { retry_after?: number }).retry_after ?? 60);
      }
      setError(extractErrorMessage(err, 'Unable to send the code.'));
      // The slot may have filled up since the calendar loaded.
      if (axios.isAxiosError(err) && err.response?.status === 409 && selectedDay) void chooseDay(selectedDay);
    } finally {
      setIsBusy(false);
    }
  }

  async function confirmBooking() {
    setError(null);
    setIsBusy(true);
    try {
      const response = await api.post<Appointment>('/appointments', { ...bookingPayload(), code });
      setBooked(response.data);
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to book this appointment.'));
      if (axios.isAxiosError(err) && err.response?.status === 409) {
        // Someone took the slot while the code was in the inbox: pick again.
        setStage('details');
        if (selectedDay) void chooseDay(selectedDay);
        if (provider) void loadCalendar(provider, month);
      }
    } finally {
      setIsBusy(false);
    }
  }

  if (booked) {
    return (
      <View className="flex-1 items-center justify-center bg-white px-6" style={{ paddingTop: insets.top }}>
        <View className="h-16 w-16 items-center justify-center rounded-full bg-amber-100">
          <Ionicons name="hourglass-outline" size={30} color="#b45309" />
        </View>
        <Text className="mt-5 text-2xl font-bold text-slate-900">Your booking is under review</Text>
        <Text className="mt-2 text-center text-sm text-slate-500">
          Nurse Portia will check it and confirm. You'll be notified, and your place in line will show in Appointments
          once it's confirmed.
        </Text>
        <View className="mt-6 w-full rounded-2xl border border-slate-200 bg-slate-50 p-5">
          <Text className="text-sm font-semibold text-slate-900">{providerLine(booked.provider)}</Text>
          <Text className="mt-1 text-sm text-slate-600">
            {formatLongDate(booked.scheduled_at)} · {formatTime(booked.scheduled_at)}
          </Text>
          <Text className="mt-1 text-sm text-slate-500">{booked.reason}</Text>
        </View>
        <Pressable onPress={() => router.replace('/(tabs)/appointments')} className="mt-8 w-full items-center rounded-lg bg-brand py-3.5">
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
      ref={scrollRef}
      className="flex-1 bg-white"
      contentContainerStyle={{ paddingTop: contentTop, paddingBottom: 48 }}
      keyboardShouldPersistTaps="handled"
      onLayout={(event) => {
        viewportHeight.current = event.nativeEvent.layout.height;
      }}
      onScroll={(event) => {
        scrollY.current = event.nativeEvent.contentOffset.y;
      }}
      scrollEventThrottle={64}
    >
      <View className="px-6">
        <View className="flex-row items-center justify-between">
          <Text className="text-2xl font-bold text-slate-900">Book Appointment</Text>
          <Pressable onPress={() => router.back()} hitSlop={8}>
            <Text className="text-sm text-slate-400">Close</Text>
          </Pressable>
        </View>

        {error && <Text className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</Text>}

        {stage === 'code' ? (
          <View className="mt-6">
            <Text className="text-base font-semibold text-slate-900">Enter the code we emailed you</Text>
            <Text className="mt-1 text-sm text-slate-500">
              We sent a 6-digit code to {sentTo}. It works for 10 minutes.
            </Text>

            <TextInput
              value={code}
              onChangeText={(text) => setCode(text.replace(/\D/g, '').slice(0, 6))}
              keyboardType="number-pad"
              autoFocus
              maxLength={6}
              placeholder="••••••"
              placeholderTextColor="#cbd5e1"
              accessibilityLabel="6-digit code"
              className="mt-5 rounded-xl border border-slate-300 py-4 text-center text-3xl font-bold text-slate-900"
              style={{ letterSpacing: 12 }}
            />

            <View className="mt-5 rounded-xl bg-slate-50 p-4">
              <Text className="text-sm font-medium text-slate-900">{provider && providerLine(provider)}</Text>
              <Text className="mt-0.5 text-sm text-slate-600">
                {selectedDay && formatLongDate(selectedDay.date)} · {selectedTime && formatSlotTime(selectedTime)}
              </Text>
            </View>

            <Pressable
              onPress={confirmBooking}
              disabled={isBusy || code.length !== 6}
              className="mt-6 items-center rounded-lg bg-brand py-3.5 disabled:opacity-50"
            >
              {isBusy ? <ActivityIndicator color="white" /> : <Text className="text-base font-semibold text-white">Confirm booking</Text>}
            </Pressable>

            <Pressable onPress={sendCode} disabled={isBusy || resendIn > 0} className="mt-4 items-center">
              <Text className={`text-sm font-medium ${resendIn > 0 ? 'text-slate-400' : 'text-blue-600'}`}>
                {resendIn > 0 ? `Send a new code in ${resendIn}s` : 'Send a new code'}
              </Text>
            </Pressable>
            <Pressable onPress={() => setStage('details')} className="mt-3 items-center">
              <Text className="text-sm text-slate-400">Change the booking</Text>
            </Pressable>
          </View>
        ) : (
          <>
            <Section step={1} title="Who do you want to see?">
              {providers === null ? (
                <ActivityIndicator />
              ) : providers.length === 0 ? (
                <Text className="text-sm text-slate-400">No one is taking bookings right now.</Text>
              ) : (
                <View className="gap-3">
                  {providers.map((option) => {
                    const isSelected = provider?.id === option.id;
                    return (
                      <Pressable
                        key={option.id}
                        onPress={() => chooseProvider(option)}
                        accessibilityRole="radio"
                        accessibilityState={{ selected: isSelected }}
                        className={`flex-row items-center gap-3 rounded-xl border p-4 ${isSelected ? 'border-brand bg-blue-50' : 'border-slate-200 bg-white'}`}
                      >
                        <View className={`h-10 w-10 items-center justify-center rounded-full ${isSelected ? 'bg-brand' : 'bg-slate-100'}`}>
                          <Ionicons
                            name={PROFESSION_ICONS[option.profession ?? 'nurse'] ?? 'person-outline'}
                            size={20}
                            color={isSelected ? '#ffffff' : '#0b1636'}
                          />
                        </View>
                        <View className="flex-1">
                          <Text className="text-sm font-semibold text-slate-900">{option.display_name}</Text>
                          <Text className="text-xs text-slate-500">{option.job_title}</Text>
                          <Text className="mt-1 text-xs text-slate-600">{describeHours(option.schedule)}</Text>
                        </View>
                        {isSelected && <Ionicons name="checkmark-circle" size={22} color="#0b1636" />}
                      </Pressable>
                    );
                  })}
                </View>
              )}
            </Section>

            {provider && (
              <Section step={2} title="Pick a day">
                <MonthCalendar
                  month={month}
                  days={calendar}
                  selected={selectedDay?.date ?? null}
                  onSelect={chooseDay}
                  onPrevious={() => shiftMonth(-1)}
                  onNext={() => shiftMonth(1)}
                  canGoBack={canGoBack}
                  canGoForward={canGoForward}
                />
              </Section>
            )}

            {/* The chosen day's times, right under the calendar. */}
            {provider && selectedDay && (
              <View className="mt-4" onLayout={revealWhenLaidOut('day')}>
                <Text className="text-sm font-semibold text-slate-700">{formatLongDate(selectedDay.date)}</Text>
                {selectedDay.status === 'event' && (
                  <Text className="mt-2 text-sm text-slate-500">
                    The clinic isn't taking bookings this day{selectedDay.event ? ` (${selectedDay.event})` : ''}. Walk-ins are
                    still welcome.
                  </Text>
                )}
                {selectedDay.status === 'full' && (
                  <Text className="mt-2 text-sm text-slate-500">This day is fully booked. Please pick another day.</Text>
                )}
                {selectedDay.status === 'open' && slots === null && <ActivityIndicator className="mt-3" />}
                {slots && slots.length === 0 && selectedDay.status === 'open' && (
                  <Text className="mt-2 text-sm text-slate-500">No times left on this day.</Text>
                )}
                {slots && slots.length > 0 && (
                  <View className="mt-3 flex-row flex-wrap gap-2">
                    {slots.map((slot) => {
                      const isSelected = selectedTime === slot.time;
                      return (
                        <Pressable
                          key={slot.time}
                          disabled={!slot.available}
                          onPress={() => {
                            if (!selectedTime) pendingReveal.current = 'reason';
                            setSelectedTime(slot.time);
                          }}
                          accessibilityLabel={`${formatSlotTime(slot.time)}${slot.available ? '' : ', taken'}`}
                          className={`rounded-lg border px-4 py-2.5 ${
                            isSelected ? 'border-brand bg-brand' : slot.available ? 'border-slate-200 bg-white' : 'border-slate-100 bg-slate-50'
                          }`}
                        >
                          <Text
                            className={`text-sm font-medium ${
                              isSelected ? 'text-white' : slot.available ? 'text-slate-900' : 'text-slate-300 line-through'
                            }`}
                          >
                            {formatSlotTime(slot.time)}
                          </Text>
                        </Pressable>
                      );
                    })}
                  </View>
                )}
              </View>
            )}

            {provider && selectedTime && (
              <Section step={3} title="Reason for the visit" onLayout={revealWhenLaidOut('reason')}>
                <View className="flex-row flex-wrap gap-2">
                  {(REASON_CHIPS[provider.profession ?? 'nurse'] ?? []).map((chip) => {
                    const isSelected = reason === chip;
                    return (
                      <Pressable
                        key={chip}
                        onPress={() => {
                          // A tap (not typing, which keeps the keyboard up) brings in the last step.
                          if (!isSelected && reason.trim() === '') pendingReveal.current = 'record';
                          setReason(isSelected ? '' : chip);
                        }}
                        className={`rounded-full border px-3 py-1.5 ${isSelected ? 'border-blue-600 bg-blue-600' : 'border-slate-300'}`}
                      >
                        <Text className={`text-xs font-medium ${isSelected ? 'text-white' : 'text-slate-600'}`}>{chip}</Text>
                      </Pressable>
                    );
                  })}
                </View>
                <TextInput
                  value={reason}
                  onChangeText={setReason}
                  multiline
                  numberOfLines={3}
                  maxLength={1000}
                  placeholder="Or describe it in your own words"
                  placeholderTextColor="#94a3b8"
                  className="mt-3 rounded-lg border border-slate-200 px-4 py-3 text-sm text-slate-900"
                  style={{ minHeight: 80, textAlignVertical: 'top' }}
                />
              </Section>
            )}

            {provider && selectedTime && reason.trim() !== '' && (
              <Section step={4} title="Your health record" onLayout={revealWhenLaidOut('record')}>
                {recordComplete && user ? (
                  <View className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <Text className="text-sm font-semibold text-slate-900">{user.name}</Text>
                    <Text className="mt-1 text-xs text-slate-600">
                      ID {user.school_id} ·{' '}
                      {user.course
                        ? `${courseName(departments, user.course) ?? user.course} (${user.department})`
                        : departmentName(departments, user.department) ?? user.department}
                    </Text>
                    <Text className="mt-0.5 text-xs text-slate-600">
                      {user.sex === 'male' ? 'Male' : 'Female'} · Born {user.birthdate ? formatBirthdate(user.birthdate) : '—'}
                    </Text>
                    <Text className="mt-0.5 text-xs text-slate-600">{user.email}{user.contact_no ? ` · ${user.contact_no}` : ''}</Text>
                    <Text className="mt-0.5 text-xs text-slate-600">{user.home_address}</Text>
                    <Pressable onPress={() => router.push('/health-record?from=booking')} className="mt-3 self-start">
                      <Text className="text-sm font-semibold text-blue-600">Edit</Text>
                    </Pressable>
                  </View>
                ) : (
                  <View className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <Text className="text-sm text-amber-900">
                      Your health record is missing a few details. The clinic needs it complete before you can book.
                    </Text>
                    <Pressable
                      onPress={() => router.push('/health-record?from=booking')}
                      className="mt-3 items-center rounded-lg bg-amber-600 py-2.5"
                    >
                      <Text className="font-semibold text-white">Complete my record</Text>
                    </Pressable>
                  </View>
                )}

                <Pressable
                  onPress={sendCode}
                  disabled={isBusy || !recordComplete || resendIn > 0}
                  className="mt-6 items-center rounded-lg bg-brand py-3.5 disabled:opacity-50"
                >
                  {isBusy ? (
                    <ActivityIndicator color="white" />
                  ) : (
                    <Text className="text-base font-semibold text-white">
                      {resendIn > 0 ? `Send code in ${resendIn}s` : 'Email me a confirmation code'}
                    </Text>
                  )}
                </Pressable>
                <Text className="mt-2 text-center text-xs text-slate-400">
                  Booking {provider.display_name} on {selectedDay && formatLongDate(selectedDay.date)} at{' '}
                  {formatSlotTime(selectedTime)}. Nurse Portia reviews every booking.
                </Text>
              </Section>
            )}
          </>
        )}
      </View>
    </ScrollView>
  );
}

