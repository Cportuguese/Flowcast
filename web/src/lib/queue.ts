import type { StatusTone } from '../components/StatusPill'

// Shared shape of a queue entry as returned by QueueController::formatEntry,
// plus the status styling used wherever entries are listed.

export interface QueueEntry {
  id: number
  queue_number: number
  status: 'waiting' | 'in_consultation' | 'done' | 'skipped'
  source: 'walk_in' | 'appointment'
  appointment_id: number | null
  /** The booked time, for patients who booked with the nurse. */
  booked_for: string | null
  /** What they said they were coming for when they booked. */
  booking_reason: string | null
  patient: {
    id: number
    name: string
    contact_no: string | null
    school_id: string | null
    /** False while the ID number is only what the patient typed (not checked against their card). */
    id_verified: boolean
    department: string | null
    course: string | null
  }
  joined_at: string | null
  called_at: string | null
  completed_at: string | null
}

export const QUEUE_STATUS_TONES: Record<QueueEntry['status'], StatusTone> = {
  waiting: 'neutral',
  in_consultation: 'info',
  done: 'success',
  skipped: 'danger',
}

export const QUEUE_STATUS_LABELS: Record<QueueEntry['status'], string> = {
  waiting: 'Waiting',
  in_consultation: 'In Consultation',
  done: 'Done',
  skipped: 'Skipped',
}

/** Done or skipped: the entry has left the queue and has no actions left. */
export function isResolved(entry: QueueEntry) {
  return entry.status === 'done' || entry.status === 'skipped'
}

/** How late a booked patient may check in and still be called at their time. */
const LATE_GRACE_MS = 15 * 60_000

/** A booked patient, checked in on time, whose booked time has come. */
export function isDueBooking(entry: QueueEntry, now = Date.now()) {
  if (!entry.booked_for || !entry.joined_at) return false
  const bookedFor = new Date(entry.booked_for).getTime()
  return bookedFor <= now && new Date(entry.joined_at).getTime() <= bookedFor + LATE_GRACE_MS
}

/**
 * Waiting entries in the order Call Next takes them — the same rule as
 * QueueController::callOrder(): booked patients whose time has come first
 * (earliest booking first), then everyone else by queue number.
 */
export function callOrder(waiting: QueueEntry[], now = Date.now()) {
  const due = waiting
    .filter((entry) => isDueBooking(entry, now))
    .sort((a, b) => new Date(a.booked_for!).getTime() - new Date(b.booked_for!).getTime() || a.queue_number - b.queue_number)
  const rest = waiting.filter((entry) => !isDueBooking(entry, now)).sort((a, b) => a.queue_number - b.queue_number)
  return [...due, ...rest]
}

/** Whole minutes between two ISO timestamps, or null if either is missing. */
export function minutesBetween(from: string | null, to: string | null): number | null {
  if (!from || !to) return null
  return Math.max(0, Math.round((new Date(to).getTime() - new Date(from).getTime()) / 60_000))
}
