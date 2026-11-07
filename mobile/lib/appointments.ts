// A patient's bookings as GET /appointments/mine returns them.

export type AppointmentStatus = 'pending' | 'booked' | 'declined' | 'cancelled' | 'checked_in' | 'no_show' | 'completed';

export interface Provider {
  id: number;
  display_name: string;
  job_title: string | null;
  profession: 'nurse' | 'dentist' | 'physician' | null;
}

export interface Appointment {
  id: number;
  scheduled_at: string;
  reason: string;
  status: AppointmentStatus;
  cancel_reason: string | null;
  review_note: string | null;
  reviewed_at: string | null;
  provider: Provider | null;
  /** Nurse bookings: check in and get a queue number. Dentist/physician: come at the time. */
  goes_through_queue: boolean;
  /** Confirmed bookings only: confirmed patients booked earlier the same day, still to be seen. */
  people_ahead: number | null;
  created_at: string | null;
}

// `booked` is what the nurse's approval turns a request into.
export const STATUS_LABELS: Record<AppointmentStatus, string> = {
  pending: 'Under review',
  booked: 'Confirmed',
  declined: 'Declined',
  cancelled: 'Cancelled',
  checked_in: 'Checked in',
  no_show: 'No-show',
  completed: 'Completed',
};

export const STATUS_STYLES: Record<AppointmentStatus, string> = {
  pending: 'bg-amber-100 text-amber-800',
  booked: 'bg-blue-100 text-blue-700',
  declined: 'bg-red-100 text-red-700',
  cancelled: 'bg-gray-100 text-gray-500',
  checked_in: 'bg-blue-100 text-blue-700',
  no_show: 'bg-red-100 text-red-700',
  completed: 'bg-green-100 text-green-700',
};

/** "Nurse Portia", "Dr. Marco Villanueva · Dentist". */
export function providerLine(provider: Provider | null) {
  if (!provider) return 'Clinic nurse';
  return provider.profession === 'nurse' || !provider.job_title
    ? provider.display_name
    : `${provider.display_name} · ${provider.job_title}`;
}

/**
 * How late a patient booked with the nurse may check in and still be
 * called at their booked time. Matches QueueController::LATE_GRACE_MINUTES.
 */
export const LATE_GRACE_MINUTES = 15;

/** Minutes since the booked time (negative while it is still ahead). */
export function minutesPastBooking(appointment: Appointment, now = Date.now()) {
  return Math.floor((now - new Date(appointment.scheduled_at).getTime()) / 60_000);
}

export function peopleAheadText(count: number) {
  if (count === 0) return 'No one is booked ahead of you';
  return count === 1 ? '1 person ahead of you' : `${count} people ahead of you`;
}
