// Shared shape of a queue entry as returned by QueueController::formatEntry,
// plus the status styling used wherever entries are listed.

export interface QueueEntry {
  id: number
  queue_number: number
  status: 'waiting' | 'in_consultation' | 'done' | 'skipped'
  source: 'walk_in' | 'appointment'
  appointment_id: number | null
  patient: { id: number; name: string; contact_no: string | null }
  joined_at: string | null
  called_at: string | null
  completed_at: string | null
}

export const QUEUE_STATUS_STYLES: Record<QueueEntry['status'], string> = {
  waiting: 'bg-slate-100 text-slate-700',
  in_consultation: 'bg-blue-100 text-blue-700',
  done: 'bg-green-100 text-green-700',
  skipped: 'bg-red-100 text-red-700',
}

export const QUEUE_STATUS_LABELS: Record<QueueEntry['status'], string> = {
  waiting: 'Waiting',
  in_consultation: 'In Consultation',
  done: 'Done',
  skipped: 'Skipped',
}

/** Whole minutes between two ISO timestamps, or null if either is missing. */
export function minutesBetween(from: string | null, to: string | null): number | null {
  if (!from || !to) return null
  return Math.max(0, Math.round((new Date(to).getTime() - new Date(from).getTime()) / 60_000))
}
