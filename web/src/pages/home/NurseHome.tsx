import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { buttonClass } from '../../components/Button'
import { PageHeader } from '../../components/PageHeader'
import { PatientSummary } from '../../components/PatientSummary'
import { ReviewActions } from '../../components/ReviewActions'
import { StatTile } from '../../components/StatTile'
import { StatusPill } from '../../components/StatusPill'
import { VisitFormModal } from '../../components/VisitFormModal'
import { api } from '../../lib/api'
import {
  APPOINTMENT_STATUS_LABELS,
  APPOINTMENT_STATUS_TONES,
  canCheckIn,
  canMarkNoShow,
  checkInBooking,
  type StaffAppointment,
} from '../../lib/appointments'
import { formatShortDate, formatTime, todayIso } from '../../lib/dates'
import { departmentLabel } from '../../lib/departments'
import { extractErrorMessage } from '../../lib/errors'
import { callOrder, isDueBooking, type QueueEntry } from '../../lib/queue'
import { usePendingReviews } from '../../lib/usePendingReviews'
import { timeInQueue, useTodayQueue } from '../../lib/useTodayQueue'

interface DashboardStats {
  is_event_day: boolean
  event_label: string | null
  stats: { avg_wait_minutes_today: number | null }
}

const UP_NEXT_LIMIT = 5
const REVIEW_LIMIT = 5

const rowBtn = 'rounded-md px-3 py-1.5 text-xs font-medium transition disabled:opacity-50'

/**
 * Nurse Portia's day: the walk-in queue she runs, the booking requests only
 * she can confirm, and what all three professionals have on today.
 */
export function NurseHome() {
  const queue = useTodayQueue()
  const { pending, reload: reloadPending } = usePendingReviews()
  const [stats, setStats] = useState<DashboardStats | null>(null)
  const [appointments, setAppointments] = useState<StaffAppointment[]>([])
  const [visitFormEntry, setVisitFormEntry] = useState<QueueEntry | null>(null)
  const [busyAppointmentId, setBusyAppointmentId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const loadAppointments = useCallback(async () => {
    const response = await api.get<StaffAppointment[]>('/appointments', { params: { date: todayIso() } })
    setAppointments(response.data)
  }, [])

  useEffect(() => {
    loadAppointments().catch((err) => setError(extractErrorMessage(err, 'Unable to load appointments for today.')))
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

  // A booked patient at the counter (no phone, or a flat battery): check
  // them in on their booking so they keep their place at the booked time.
  async function handleCheckIn(appointment: StaffAppointment) {
    setError(null)
    setBusyAppointmentId(appointment.id)
    try {
      if (await checkInBooking(appointment)) await loadAppointments()
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to check this patient in.'))
    } finally {
      setBusyAppointmentId(null)
    }
  }

  function handleReviewed() {
    void reloadPending()
    void loadAppointments()
  }

  const upNext = callOrder(queue.waiting).slice(0, UP_NEXT_LIMIT)
  // Today's confirmed bookings across all three professionals; requests
  // still under review are in their own card.
  const todaysBookings = appointments.filter((appt) => appt.status !== 'pending' && appt.status !== 'declined')
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

        <div className="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
          <StatTile label="Waiting" value={queue.waiting.length} />
          <StatTile label="In consultation" value={queue.inConsultation.length} />
          <StatTile label="Done today" value={queue.doneCount} />
          <StatTile label="Avg wait" value={avgWait != null ? `${avgWait} min` : '—'} />
          <StatTile label="To review" value={pending.length} hint="booking requests" />
        </div>

        <section className="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-medium uppercase text-slate-500">Now serving</p>
          {queue.inConsultation.length === 0 ? (
            <p className="mt-2 text-sm text-slate-400">No one is in consultation.</p>
          ) : (
            <ul className="mt-2 divide-y divide-slate-100">
              {queue.inConsultation.map((entry) => (
                <li key={entry.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div className="flex items-center gap-3">
                    <span className="text-2xl font-semibold text-gray-900">#{entry.queue_number}</span>
                    <div>
                      <PatientSummary patient={entry.patient} />
                      <span className="text-xs text-slate-500">
                        {entry.source === 'appointment' ? 'booked' : 'walk-in'}
                        {entry.called_at && ` · called ${formatTime(entry.called_at)}`}
                      </span>
                    </div>
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
            <p className="text-xs text-slate-500">Booked patients are called at their booked time.</p>
            {queue.isLoading ? (
              <p className="mt-3 text-sm text-slate-400">Loading…</p>
            ) : upNext.length === 0 ? (
              <p className="mt-3 text-sm text-slate-400">No one is waiting.</p>
            ) : (
              <ul className="mt-3 divide-y divide-slate-100">
                {upNext.map((entry) => (
                  <li key={entry.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                    <span className="flex min-w-0 items-center gap-3">
                      <span className="font-semibold text-slate-900">#{entry.queue_number}</span>
                      <span className="min-w-0">
                        <span className="block truncate text-slate-700">{entry.patient.name}</span>
                        <span className="block text-xs text-slate-400">{departmentLabel(entry.patient)}</span>
                      </span>
                    </span>
                    <span className="flex shrink-0 items-center gap-2 text-xs text-slate-500">
                      {entry.booked_for && (
                        <StatusPill tone={isDueBooking(entry) ? 'info' : 'neutral'}>
                          Booked {formatTime(entry.booked_for)}
                        </StatusPill>
                      )}
                      {timeInQueue(entry)}
                    </span>
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
              Bookings to review{pending.length > 0 && ` (${pending.length})`}
            </h2>
            <p className="text-xs text-slate-500">Patients see "under review" until you confirm.</p>
            {pending.length === 0 ? (
              <p className="mt-3 text-sm text-slate-400">Nothing to review.</p>
            ) : (
              <ul className="mt-3 divide-y divide-slate-100">
                {pending.slice(0, REVIEW_LIMIT).map((appt) => (
                  <li key={appt.id} className="space-y-2 py-3 text-sm">
                    <div className="flex items-start justify-between gap-3">
                      <PatientSummary patient={appt.patient} />
                      <span className="shrink-0 text-right text-xs text-slate-500">
                        {formatShortDate(appt.scheduled_at.slice(0, 10))} · {formatTime(appt.scheduled_at)}
                        <br />
                        {appt.provider?.display_name}
                      </span>
                    </div>
                    <p className="text-xs text-slate-600">{appt.reason}</p>
                    <ReviewActions appointment={appt} onReviewed={handleReviewed} />
                  </li>
                ))}
              </ul>
            )}
            <Link to="/appointments" className="mt-3 inline-block text-sm font-medium text-blue-600 hover:underline">
              All bookings →
            </Link>
          </section>
        </div>

        <section className="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-900">
            Appointments today{todaysBookings.length > 0 && ` (${todaysBookings.length})`}
          </h2>
          {todaysBookings.length === 0 ? (
            <p className="mt-3 text-sm text-slate-400">No appointments today.</p>
          ) : (
            <ul className="mt-3 divide-y divide-slate-100">
              {todaysBookings.map((appt) => (
                <li key={appt.id} className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                  <span className="flex min-w-0 items-center gap-3">
                    <span className="w-16 shrink-0 text-slate-500">{formatTime(appt.scheduled_at)}</span>
                    <PatientSummary patient={appt.patient} />
                  </span>
                  <span className="flex shrink-0 items-center gap-2">
                    <span className="text-xs text-slate-500">{appt.provider?.display_name}</span>
                    <StatusPill tone={APPOINTMENT_STATUS_TONES[appt.status]}>{APPOINTMENT_STATUS_LABELS[appt.status]}</StatusPill>
                    {canCheckIn(appt) && (
                      <button
                        type="button"
                        disabled={busyAppointmentId === appt.id}
                        onClick={() => handleCheckIn(appt)}
                        className="text-xs font-medium text-blue-600 hover:underline disabled:opacity-50"
                      >
                        Check in
                      </button>
                    )}
                    {canMarkNoShow(appt) && (
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
        </section>
      </div>

      {visitFormEntry && (
        <VisitFormModal
          source={{ queueEntryId: visitFormEntry.id }}
          patientName={visitFormEntry.patient.name}
          initialComplaint={visitFormEntry.booking_reason ?? undefined}
          onClose={() => setVisitFormEntry(null)}
          onSaved={() => setVisitFormEntry(null)}
        />
      )}
    </>
  )
}
