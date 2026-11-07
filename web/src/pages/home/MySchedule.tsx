import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/PageHeader'
import { PatientSummary } from '../../components/PatientSummary'
import { StatusPill } from '../../components/StatusPill'
import { VisitFormModal } from '../../components/VisitFormModal'
import { useAuth } from '../../context/AuthContext'
import { api } from '../../lib/api'
import {
  APPOINTMENT_STATUS_LABELS,
  APPOINTMENT_STATUS_TONES,
  isDue,
  isOpenAppointment,
  type StaffAppointment,
} from '../../lib/appointments'
import { formatClock, formatTime, isoDaysFromToday, todayIso } from '../../lib/dates'
import { extractErrorMessage } from '../../lib/errors'

interface ScheduleRow {
  day_of_week: number
  start_time: string
  end_time: string
}

interface ProviderListing {
  id: number
  schedule: ScheduleRow[]
}

/** How far ahead the upcoming list looks. */
const UPCOMING_DAYS = 14

const DAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

/** Quick-pick complaints for the visit form, per profession. */
const COMPLAINT_CHIPS: Record<string, string[]> = {
  dentist: ['Dental check-up and cleaning', 'Toothache, for extraction', 'Tooth cavity', 'Bleeding gums', 'Broken tooth'],
  physician: ['Medical clearance', 'Persistent cough', 'Recurring headaches', 'Blood pressure follow-up', 'Skin rash', 'Fever for three days'],
}

const rowBtn = 'rounded-md px-3 py-1.5 text-xs font-medium transition disabled:opacity-50'

/** "Tue & Thu · 8:00 AM–5:00 PM" — days with the same hours grouped together. */
function describeHours(schedule: ScheduleRow[]) {
  const groups = new Map<string, number[]>()
  for (const row of schedule) {
    const hours = `${formatClock(row.start_time)}–${formatClock(row.end_time)}`
    groups.set(hours, [...(groups.get(hours) ?? []), row.day_of_week])
  }
  return [...groups.entries()]
    .map(([hours, days]) => {
      const names = days.map((day) => DAY_SHORT[day])
      const list = names.length > 1 ? `${names.slice(0, -1).join(', ')} & ${names[names.length - 1]}` : names[0]
      return `${list} · ${hours}`
    })
    .join('; ')
}

function dayHeading(iso: string) {
  return new Date(iso).toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric' })
}

/**
 * The dentist's and the school physician's home: only their own bookings.
 * Patients come at their booked time (no queue number); "Done" records the
 * visit, which also marks the booking completed.
 */
export function MySchedule() {
  const { user } = useAuth()
  const [hours, setHours] = useState<ScheduleRow[] | null>(null)
  const [today, setToday] = useState<StaffAppointment[]>([])
  const [upcoming, setUpcoming] = useState<StaffAppointment[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [recording, setRecording] = useState<StaffAppointment | null>(null)

  const load = useCallback(async () => {
    const [todayRes, upcomingRes] = await Promise.all([
      api.get<StaffAppointment[]>('/appointments', { params: { date: todayIso() } }),
      api.get<StaffAppointment[]>('/appointments', { params: { from: isoDaysFromToday(1), to: isoDaysFromToday(UPCOMING_DAYS) } }),
    ])
    setToday(todayRes.data)
    setUpcoming(upcomingRes.data)
  }, [])

  useEffect(() => {
    load()
      .catch((err) => setError(extractErrorMessage(err, 'Unable to load your schedule.')))
      .finally(() => setIsLoading(false))

    api
      .get<ProviderListing[]>('/providers')
      .then((response) => setHours(response.data.find((provider) => provider.id === user?.id)?.schedule ?? []))
      .catch(() => setHours([]))
  }, [load, user?.id])

  async function handleNoShow(appointment: StaffAppointment) {
    setError(null)
    setBusyId(appointment.id)
    try {
      await api.patch(`/appointments/${appointment.id}/no-show`)
      await load()
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to mark this booking as a no-show.'))
    } finally {
      setBusyId(null)
    }
  }

  // Requests the head nurse has not reviewed yet are not on anyone's
  // schedule; they are only counted, so nothing is a surprise.
  const confirmedToday = today.filter((appt) => !['pending', 'declined', 'cancelled'].includes(appt.status))
  const awaitingReview = [...today, ...upcoming].filter((appt) => appt.status === 'pending').length
  const upcomingByDay = new Map<string, StaffAppointment[]>()
  for (const appt of upcoming.filter((a) => isOpenAppointment(a))) {
    const day = appt.scheduled_at.slice(0, 10)
    upcomingByDay.set(day, [...(upcomingByDay.get(day) ?? []), appt])
  }

  return (
    <>
      <div className="mx-auto max-w-4xl">
        <PageHeader
          title="My Schedule"
          subtitle={
            <>
              {user?.display_name}
              {user?.job_title && ` · ${user.job_title}`}
              {hours && hours.length > 0 && ` · ${describeHours(hours)}`}
            </>
          }
        />

        {error && (
          <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
        )}

        {hours && hours.length === 0 && (
          <p className="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
            You have no booking hours yet, so patients cannot book you. Ask Nurse Portia to set them in Clinic Settings.
          </p>
        )}

        {awaitingReview > 0 && (
          <p className="mt-4 rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-600">
            {awaitingReview} {awaitingReview === 1 ? 'booking is' : 'bookings are'} waiting for Nurse Portia's review and will
            appear here once confirmed.
          </p>
        )}

        <section className="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-900">
            Today{confirmedToday.length > 0 && ` (${confirmedToday.length})`}
          </h2>
          {isLoading ? (
            <p className="mt-3 text-sm text-slate-400">Loading…</p>
          ) : confirmedToday.length === 0 ? (
            <p className="mt-3 text-sm text-slate-400">No patients booked today.</p>
          ) : (
            <ul className="mt-3 divide-y divide-slate-100">
              {confirmedToday.map((appt) => (
                <li key={appt.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                  <div className="flex min-w-0 items-start gap-4">
                    <span className="w-16 shrink-0 pt-0.5 font-semibold text-slate-900">{formatTime(appt.scheduled_at)}</span>
                    <div className="min-w-0">
                      <PatientSummary patient={appt.patient} />
                      <p className="mt-0.5 text-xs text-slate-600">{appt.reason}</p>
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    {isOpenAppointment(appt) ? (
                      <>
                        <button
                          type="button"
                          disabled={busyId === appt.id}
                          onClick={() => setRecording(appt)}
                          className={`${rowBtn} bg-green-600 text-white hover:bg-green-700`}
                        >
                          Done
                        </button>
                        {isDue(appt) && (
                          <button
                            type="button"
                            disabled={busyId === appt.id}
                            onClick={() => handleNoShow(appt)}
                            className={`${rowBtn} bg-red-100 text-red-700 hover:bg-red-200`}
                          >
                            No-show
                          </button>
                        )}
                      </>
                    ) : (
                      <StatusPill tone={APPOINTMENT_STATUS_TONES[appt.status]}>{APPOINTMENT_STATUS_LABELS[appt.status]}</StatusPill>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-900">Next {UPCOMING_DAYS} days</h2>
          {upcomingByDay.size === 0 ? (
            <p className="mt-3 text-sm text-slate-400">No confirmed bookings yet.</p>
          ) : (
            <div className="mt-3 space-y-5">
              {[...upcomingByDay.entries()].map(([day, appointments]) => (
                <div key={day}>
                  <p className="text-xs font-semibold uppercase text-slate-500">
                    {dayHeading(appointments[0].scheduled_at)} · {appointments.length} confirmed
                  </p>
                  <ul className="mt-1 divide-y divide-slate-100">
                    {appointments.map((appt) => (
                      <li key={appt.id} className="flex items-start gap-4 py-2 text-sm">
                        <span className="w-16 shrink-0 text-slate-500">{formatTime(appt.scheduled_at)}</span>
                        <div className="min-w-0">
                          <PatientSummary patient={appt.patient} />
                          <p className="mt-0.5 text-xs text-slate-600">{appt.reason}</p>
                        </div>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          )}
        </section>
      </div>

      {recording && (
        <VisitFormModal
          source={{ appointmentId: recording.id }}
          patientName={recording.patient.name}
          initialComplaint={recording.reason}
          complaintChips={COMPLAINT_CHIPS[user?.profession ?? ''] ?? undefined}
          onClose={() => setRecording(null)}
          onSaved={() => {
            setRecording(null)
            void load()
          }}
        />
      )}
    </>
  )
}
