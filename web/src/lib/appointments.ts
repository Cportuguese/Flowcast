import type { StatusTone } from '../components/StatusPill'
import { api } from './api'
import { formatBirthdate, todayIso } from './dates'

// Bookings as the staff endpoints return them (AppointmentController::formatForStaff).

export type AppointmentStatus = 'pending' | 'booked' | 'declined' | 'cancelled' | 'checked_in' | 'no_show' | 'completed'

export type Profession = 'nurse' | 'dentist' | 'physician'

export interface Provider {
  id: number
  display_name: string
  job_title: string | null
  profession: Profession | null
}

export interface StaffAppointment {
  id: number
  scheduled_at: string
  reason: string
  status: AppointmentStatus
  cancel_reason: string | null
  review_note: string | null
  reviewed_at: string | null
  created_at: string | null
  goes_through_queue: boolean
  visit_id: number | null
  provider: Provider | null
  patient: {
    id: number
    name: string
    email: string
    contact_no: string | null
    school_id: string | null
    /** False while the ID number is only what the patient typed (not checked against their card). */
    id_verified: boolean
    department: string | null
    course: string | null
    sex: 'male' | 'female' | null
    birthdate: string | null
    patient_type: 'student' | 'faculty' | 'staff' | null
  }
}

// `booked` is what the nurse's approval turns a request into, so patients
// and staff alike see it as "Confirmed".
export const APPOINTMENT_STATUS_LABELS: Record<AppointmentStatus, string> = {
  pending: 'Under review',
  booked: 'Confirmed',
  declined: 'Declined',
  cancelled: 'Cancelled',
  checked_in: 'Checked in',
  no_show: 'No-show',
  completed: 'Completed',
}

export const APPOINTMENT_STATUS_TONES: Record<AppointmentStatus, StatusTone> = {
  pending: 'warning',
  booked: 'info',
  declined: 'danger',
  cancelled: 'muted',
  checked_in: 'info',
  no_show: 'danger',
  completed: 'success',
}

/** Confirmed and not yet seen. */
export function isOpenAppointment(appointment: { status: AppointmentStatus }) {
  return appointment.status === 'booked' || appointment.status === 'checked_in'
}

/** Its booked time has come, so it can be marked a no-show. */
export function isDue(appointment: { scheduled_at: string }) {
  return new Date(appointment.scheduled_at).getTime() <= Date.now()
}

/**
 * A confirmed booking with the nurse for today, so she can check the
 * patient in to her queue on it (keeping their place at the booked time).
 * The booking's own date is read off its ISO string, which carries the
 * clinic's offset.
 */
export function canCheckIn(appointment: StaffAppointment) {
  return appointment.status === 'booked' && appointment.goes_through_queue && appointment.scheduled_at.slice(0, 10) === todayIso()
}

/**
 * Confirmed, not checked in, and its time has come. A checked-in patient
 * is in the queue instead, where Skip settles them.
 */
export function canMarkNoShow(appointment: StaffAppointment) {
  return appointment.status === 'booked' && isDue(appointment)
}

/**
 * Before checking in someone whose ID number is only what they typed into
 * the app, the nurse compares it with their UDD ID card. OK marks the
 * number checked; Cancel stops here. Resolves whether to go on.
 */
export async function confirmIdAtCounter(patient: {
  id: number
  name: string
  school_id: string | null
  id_verified?: boolean
  birthdate?: string | null
}) {
  if (patient.id_verified !== false || !patient.school_id) return true

  const born = patient.birthdate ? `\nDate of birth on file: ${formatBirthdate(patient.birthdate)}` : ''
  const matches = window.confirm(
    `Check ${patient.name}'s UDD ID card before checking them in.\n\nID number on file: ${patient.school_id}${born}\n\nPress OK if the card matches. If it doesn't, cancel and use Live Queue › Register walk-in to sort it out.`,
  )
  if (!matches) return false

  await api.patch(`/admin/patients/${patient.id}/verify-id`)
  return true
}

/**
 * Check a booked patient in to today's queue on their booking, after the
 * ID check above. Resolves false if the nurse stopped at the ID check.
 */
export async function checkInBooking(appointment: StaffAppointment) {
  if (!(await confirmIdAtCounter(appointment.patient))) return false
  await api.post('/queue/check-in', { patient_id: appointment.patient.id, appointment_id: appointment.id })
  return true
}
