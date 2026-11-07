import { useState } from 'react'
import { api } from '../lib/api'
import type { StaffAppointment } from '../lib/appointments'
import { extractErrorMessage } from '../lib/errors'
import { useToast } from './Toast'

interface Props {
  appointment: StaffAppointment
  /** Called with the updated booking once it is confirmed or declined. */
  onReviewed: (appointment: StaffAppointment) => void
}

const rowBtn = 'rounded-md px-3 py-1.5 text-xs font-medium transition disabled:opacity-50'

/**
 * The head nurse's two answers to a booking under review. Declining asks
 * for a short reason, which the patient sees on their phone.
 */
export function ReviewActions({ appointment, onReviewed }: Props) {
  const toast = useToast()
  const [isDeclining, setIsDeclining] = useState(false)
  const [note, setNote] = useState('')
  const [isBusy, setIsBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function send(action: 'approve' | 'decline') {
    setError(null)
    setIsBusy(true)
    try {
      const response = await api.patch<StaffAppointment>(
        `/appointments/${appointment.id}/${action}`,
        action === 'decline' ? { review_note: note.trim() || null } : undefined,
      )
      toast(action === 'approve' ? `Confirmed ${appointment.patient.name}'s booking.` : 'Booking declined.')
      onReviewed(response.data)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to update this booking.'))
    } finally {
      setIsBusy(false)
    }
  }

  if (isDeclining) {
    return (
      <div className="flex flex-wrap items-center gap-2">
        <input
          autoFocus
          value={note}
          onChange={(e) => setNote(e.target.value)}
          maxLength={255}
          placeholder="Reason the patient will see"
          aria-label="Reason for declining"
          className="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1.5 text-xs focus:border-blue-600 focus:outline-none"
        />
        <button type="button" disabled={isBusy} onClick={() => send('decline')} className={`${rowBtn} bg-red-600 text-white hover:bg-red-700`}>
          Decline
        </button>
        <button type="button" disabled={isBusy} onClick={() => setIsDeclining(false)} className={`${rowBtn} text-slate-500 hover:bg-slate-100`}>
          Back
        </button>
        {error && <p className="w-full text-xs text-red-700">{error}</p>}
      </div>
    )
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <button type="button" disabled={isBusy} onClick={() => send('approve')} className={`${rowBtn} bg-green-600 text-white hover:bg-green-700`}>
        Confirm
      </button>
      <button type="button" disabled={isBusy} onClick={() => setIsDeclining(true)} className={`${rowBtn} bg-red-100 text-red-700 hover:bg-red-200`}>
        Decline…
      </button>
      {error && <p className="w-full text-xs text-red-700">{error}</p>}
    </div>
  )
}
