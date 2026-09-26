import axios from 'axios'
import { useCallback, useEffect, useState } from 'react'
import { api } from '../lib/api'
import { PageHeader } from '../components/PageHeader'
import { StatusPill, type StatusTone } from '../components/StatusPill'

interface Appointment {
  id: number
  scheduled_at: string
  reason: string
  status: 'booked' | 'cancelled' | 'checked_in' | 'no_show' | 'completed'
  cancel_reason: string | null
  patient: { id: number; name: string; email: string; contact_no: string | null }
}

const STATUS_TONES: Record<Appointment['status'], StatusTone> = {
  booked: 'neutral',
  checked_in: 'info',
  completed: 'success',
  cancelled: 'muted',
  no_show: 'danger',
}

function todayString() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

function extractErrorMessage(err: unknown, fallback: string) {
  if (axios.isAxiosError(err) && err.response) {
    const data = err.response.data as { message?: string }
    return data.message ?? fallback
  }
  return fallback
}

export function Appointments() {

  const [date, setDate] = useState(todayString())
  const [appointments, setAppointments] = useState<Appointment[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)

  const load = useCallback((forDate: string) => {
    setIsLoading(true)
    api
      .get<Appointment[]>('/appointments', { params: { date: forDate } })
      .then((response) => setAppointments(response.data))
      .catch((err) => setError(extractErrorMessage(err, 'Unable to load appointments.')))
      .finally(() => setIsLoading(false))
  }, [])

  useEffect(() => {
    load(date)
    // Only the initial load runs as an effect; later loads are triggered
    // directly by the date input's change handler below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function handleDateChange(nextDate: string) {
    setDate(nextDate)
    load(nextDate)
  }

  async function handleMarkNoShow(id: number) {
    setError(null)
    setBusyId(id)
    try {
      await api.patch(`/appointments/${id}/no-show`)
      load(date)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to mark this appointment as a no-show.'))
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title="Appointments" />

      {error && (
        <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
      )}

      <div className="mt-6 flex items-center gap-3">
        <label htmlFor="appointments-date" className="text-sm font-medium text-slate-700">
          Date
        </label>
        <input
          id="appointments-date"
          type="date"
          value={date}
          onChange={(e) => handleDateChange(e.target.value)}
          className="rounded border border-slate-300 px-3 py-2 text-sm"
        />
      </div>

      <div className="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table className="w-full min-w-[560px] text-left text-sm">
          <thead className="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3">Time</th>
              <th className="px-4 py-3">Patient</th>
              <th className="px-4 py-3">Reason</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  Loading…
                </td>
              </tr>
            )}
            {!isLoading && appointments.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  No appointments booked for this date.
                </td>
              </tr>
            )}
            {appointments.map((appointment) => (
              <tr key={appointment.id}>
                <td className="px-4 py-3 font-medium text-slate-900">
                  {new Date(appointment.scheduled_at).toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                  })}
                </td>
                <td className="px-4 py-3">
                  <div className="font-medium text-slate-900">{appointment.patient.name}</div>
                  {appointment.patient.contact_no && (
                    <div className="text-xs text-slate-500">{appointment.patient.contact_no}</div>
                  )}
                </td>
                <td className="px-4 py-3 text-slate-600">{appointment.reason}</td>
                <td className="px-4 py-3">
                  <StatusPill tone={STATUS_TONES[appointment.status]}>
                    {appointment.status.replace('_', ' ')}
                  </StatusPill>
                </td>
                <td className="px-4 py-3">
                  {(appointment.status === 'booked' || appointment.status === 'checked_in') && (
                    <button
                      type="button"
                      disabled={busyId === appointment.id}
                      onClick={() => handleMarkNoShow(appointment.id)}
                      className="rounded bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-200 disabled:opacity-50"
                    >
                      Mark No-show
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
