import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { buttonClass } from '../../components/Button'
import { PageHeader } from '../../components/PageHeader'
import { StatTile } from '../../components/StatTile'
import { StatusPill, type StatusTone } from '../../components/StatusPill'
import { VisitFormModal } from '../../components/VisitFormModal'
import { api } from '../../lib/api'
import { extractErrorMessage } from '../../lib/errors'
import type { QueueEntry } from '../../lib/queue'
import { timeInQueue, useTodayQueue } from '../../lib/useTodayQueue'

interface DashboardStats {
  is_event_day: boolean
  event_label: string | null
  stats: { avg_wait_minutes_today: number | null }
}

interface Appointment {
  id: number
  scheduled_at: string
  status: 'booked' | 'cancelled' | 'checked_in' | 'no_show' | 'completed'
  patient: { id: number; name: string }
}

const APPT_LABELS: Record<Appointment['status'], string> = {
  booked: 'Booked',
  checked_in: 'Checked in',
  completed: 'Completed',
  cancelled: 'Cancelled',
  no_show: 'No-show',
}

const APPT_TONES: Record<Appointment['status'], StatusTone> = {
  booked: 'neutral',
  checked_in: 'info',
  completed: 'success',
  cancelled: 'muted',
  no_show: 'danger',
}

const UP_NEXT_LIMIT = 5
const APPOINTMENTS_LIMIT = 6

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function formatTime(iso: string) {
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function isOpen(appt: Appointment) {
  return appt.status === 'booked' || appt.status === 'checked_in'
}

const rowBtn = 'rounded-md px-3 py-1.5 text-xs font-medium transition disabled:opacity-50'

export function PersonnelHome() {
  const queue = useTodayQueue()
  const [stats, setStats] = useState<DashboardStats | null>(null)
  const [appointments, setAppointments] = useState<Appointment[]>([])
  const [visitFormEntry, setVisitFormEntry] = useState<QueueEntry | null>(null)
  const [busyAppointmentId, setBusyAppointmentId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const loadAppointments = useCallback(async () => {
    const response = await api.get<Appointment[]>('/appointments', { params: { date: todayIso() } })
    setAppointments(response.data)
  }, [])

  useEffect(() => {
    loadAppointments().catch((err) =>
      setError(extractErrorMessage(err, 'Unable to load appointments for today.')),
    )
  }, [loadAppointments])

  // Avg wait + event-day flag come from the analytics endpoint; refresh
  // whenever the live queue changes so the tile tracks the day.
  useEffect(() => {
    if (queue.isLoading) return
    api
      .get<DashboardStats>('/analytics/dashboard')
      .then((response) => setStats(response.data))
      .catch(() => {})
  }, [queue.entries, queue.isLoading])

  async function handleMarkDone(entry: QueueEntry) {
    if (await queue.complete(entry)) setVisitFormEntry(entry)
  }

  async function handleNoShow(id: number) {
    setError(null)
    setBusyAppointmentId(id)
    try {
      await api.patch(`/appointments/${id}/no-show`)
      await loadAppointments()
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to mark this appointment as a no-show.'))
    } finally {
      setBusyAppointmentId(null)
    }
  }

  const upNext = queue.waiting.slice(0, UP_NEXT_LIMIT)
  // Actionable appointments first, then the rest, capped for the card.
  const visibleAppointments = [...appointments]
    .sort((a, b) => Number(isOpen(b)) - Number(isOpen(a)))
    .slice(0, APPOINTMENTS_LIMIT)

  const avgWait = stats?.stats.avg_wait_minutes_today

  return (
    <>
      <div className="mx-auto max-w-5xl">
        <PageHeader title="Today" />

        {stats?.is_event_day && (
          <p className="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Event day{stats.event_label ? `: ${stats.event_label}` : ''} — expect higher volume.
          </p>
        )}
        {(queue.actionError || error) && (
          <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            {queue.actionError ?? error}
          </p>
        )}

        <div className="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatTile label="Waiting" value={queue.waiting.length} />
          <StatTile label="In consultation" value={queue.inConsultation.length} />
          <StatTile label="Done today" value={queue.doneCount} />
          <StatTile label="Avg wait" value={avgWait != null ? `${avgWait} min` : '—'} />
        </div>

        <section className="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-medium uppercase text-slate-500">Now serving</p>
          {queue.inConsultation.length === 0 ? (
            <p className="mt-2 text-sm text-slate-400">No one is in consultation.</p>
          ) : (
            <ul className="mt-2 divide-y divide-slate-100">
              {queue.inConsultation.map((entry) => (
                <li key={entry.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div>
                    <span className="text-2xl font-semibold text-gray-900">#{entry.queue_number}</span>
                    <span className="ml-3 text-sm font-medium text-slate-900">{entry.patient.name}</span>
                    <span className="ml-2 text-xs text-slate-500">
                      {entry.source.replace('_', ' ')}
                      {entry.called_at && ` · called ${formatTime(entry.called_at)}`}
                    </span>
                  </div>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      disabled={queue.busyEntryId === entry.id}
                      onClick={() => handleMarkDone(entry)}
                      className={`${rowBtn} bg-green-600 text-white hover:bg-green-700`}
                    >
                      Mark Done
                    </button>
                    <button
                      type="button"
                      disabled={queue.busyEntryId === entry.id}
                      onClick={() => queue.skip(entry.id)}
                      className={`${rowBtn} bg-red-100 text-red-700 hover:bg-red-200`}
                    >
                      Mark Skipped
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={queue.callNext}
            disabled={queue.isCallingNext || queue.waiting.length === 0}
            className={buttonClass.primary}
          >
            {queue.isCallingNext ? 'Calling…' : `Call Next (${queue.waiting.length} waiting)`}
          </button>
          <Link to="/queue?walkin=1" className={buttonClass.outline}>
            Register walk-in
          </Link>
        </div>

        <div className="mt-6 grid gap-6 lg:grid-cols-2">
          <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="text-sm font-semibold text-slate-900">Up next</h2>
            {queue.isLoading ? (
              <p className="mt-3 text-sm text-slate-400">Loading…</p>
            ) : upNext.length === 0 ? (
              <p className="mt-3 text-sm text-slate-400">No one is waiting.</p>
            ) : (
              <ul className="mt-3 divide-y divide-slate-100">
                {upNext.map((entry) => (
                  <li key={entry.id} className="flex items-center justify-between py-2 text-sm">
                    <span>
                      <span className="font-semibold text-slate-900">#{entry.queue_number}</span>
                      <span className="ml-3 text-slate-700">{entry.patient.name}</span>
                    </span>
                    <span className="text-xs text-slate-500">{timeInQueue(entry)}</span>
                  </li>
                ))}
              </ul>
            )}
            <Link to="/queue" className="mt-3 inline-block text-sm font-medium text-blue-600 hover:underline">
              See full queue →
            </Link>
          </section>

          <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="text-sm font-semibold text-slate-900">
              Appointments today{appointments.length > 0 && ` (${appointments.length})`}
            </h2>
            {visibleAppointments.length === 0 ? (
              <p className="mt-3 text-sm text-slate-400">No appointments today.</p>
            ) : (
              <ul className="mt-3 divide-y divide-slate-100">
                {visibleAppointments.map((appt) => (
                  <li key={appt.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                    <span className="min-w-0">
                      <span className="text-slate-500">{formatTime(appt.scheduled_at)}</span>
                      <span className="ml-3 truncate text-slate-700">{appt.patient.name}</span>
                    </span>
                    <span className="flex shrink-0 items-center gap-2">
                      <StatusPill tone={APPT_TONES[appt.status]}>{APPT_LABELS[appt.status]}</StatusPill>
                      {isOpen(appt) && (
                        <button
                          type="button"
                          disabled={busyAppointmentId === appt.id}
                          onClick={() => handleNoShow(appt.id)}
                          className="text-xs font-medium text-red-600 hover:underline disabled:opacity-50"
                        >
                          Mark No-show
                        </button>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            )}
            <Link to="/appointments" className="mt-3 inline-block text-sm font-medium text-blue-600 hover:underline">
              See all →
            </Link>
          </section>
        </div>
      </div>

      {visitFormEntry && (
        <VisitFormModal
          queueEntryId={visitFormEntry.id}
          patientName={visitFormEntry.patient.name}
          onClose={() => setVisitFormEntry(null)}
          onSaved={() => setVisitFormEntry(null)}
        />
      )}
    </>
  )
}
