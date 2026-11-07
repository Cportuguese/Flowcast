import { useCallback, useEffect, useState } from 'react'
import { api } from '../lib/api'
import {
  APPOINTMENT_STATUS_LABELS,
  APPOINTMENT_STATUS_TONES,
  canCheckIn,
  canMarkNoShow,
  checkInBooking,
  isDue,
  type Provider,
  type StaffAppointment,
} from '../lib/appointments'
import { formatShortDate, formatTime, todayIso } from '../lib/dates'
import { extractErrorMessage } from '../lib/errors'
import { useLatestRequest } from '../lib/useLatestRequest'
import { usePendingReviews } from '../lib/usePendingReviews'
import { PageHeader } from '../components/PageHeader'
import { PatientSummary } from '../components/PatientSummary'
import { ReviewActions } from '../components/ReviewActions'
import { StatusPill } from '../components/StatusPill'
import { useToast } from '../components/Toast'

type Tab = 'review' | 'schedule'

/**
 * Every booking with the three professionals. "For review" is where the
 * head nurse confirms or declines requests (patients see "under review"
 * until she does); "Schedule" is a day's bookings, for one professional or
 * all of them.
 */
export function Appointments() {
  const toast = useToast()
  const { pending, reload: reloadPending } = usePendingReviews()
  const [tab, setTab] = useState<Tab>('review')
  const [date, setDate] = useState(todayIso)
  const [providerId, setProviderId] = useState('')
  const [providers, setProviders] = useState<Provider[]>([])
  const [appointments, setAppointments] = useState<StaffAppointment[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)

  useEffect(() => {
    api
      .get<Provider[]>('/providers')
      .then((response) => setProviders(response.data))
      .catch(() => setProviders([]))
  }, [])

  const begin = useLatestRequest()

  const load = useCallback(
    async (forDate: string, forProvider: string) => {
      const isCurrent = begin()
      setIsLoading(true)
      try {
        const response = await api.get<StaffAppointment[]>('/appointments', {
          params: { date: forDate, provider_id: forProvider || undefined },
        })
        if (isCurrent()) setAppointments(response.data)
      } catch (err) {
        if (isCurrent()) setError(extractErrorMessage(err, 'Unable to load appointments.'))
      } finally {
        if (isCurrent()) setIsLoading(false)
      }
    },
    [begin],
  )

  useEffect(() => {
    void load(date, providerId)
  }, [load, date, providerId])

  async function handleMarkNoShow(appointment: StaffAppointment) {
    setError(null)
    setBusyId(appointment.id)
    try {
      await api.patch(`/appointments/${appointment.id}/no-show`)
      toast(`${appointment.patient.name} marked as a no-show.`)
      await load(date, providerId)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to mark this appointment as a no-show.'))
    } finally {
      setBusyId(null)
    }
  }

  async function handleCheckIn(appointment: StaffAppointment) {
    setError(null)
    setBusyId(appointment.id)
    try {
      if (await checkInBooking(appointment)) {
        toast(`${appointment.patient.name} is checked in to the queue.`)
        await load(date, providerId)
      }
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to check this patient in.'))
    } finally {
      setBusyId(null)
    }
  }

  function handleReviewed() {
    void reloadPending()
    void load(date, providerId)
  }

  const tabClass = (active: boolean) =>
    `rounded-t-lg px-4 py-2 text-sm font-medium ${active ? 'border border-b-white bg-white text-slate-900' : 'text-slate-500 hover:text-slate-700'}`

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader title="Appointments" />

      {error && (
        <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
      )}

      <div className="mt-6 flex flex-wrap items-end gap-1 border-b border-slate-200">
        <button type="button" onClick={() => setTab('review')} className={tabClass(tab === 'review')}>
          For review{pending.length > 0 && ` (${pending.length})`}
        </button>
        <button type="button" onClick={() => setTab('schedule')} className={tabClass(tab === 'schedule')}>
          Schedule
        </button>
      </div>

      {tab === 'review' && (
        // On a phone the table's Confirm/Decline would sit off-screen, so
        // the same requests are shown as cards there.
        <ul className="divide-y divide-slate-100 rounded-b-lg rounded-tr-lg border border-slate-200 bg-white shadow-sm sm:hidden">
          {pending.length === 0 && <li className="px-4 py-6 text-center text-sm text-slate-400">No bookings are waiting for review.</li>}
          {pending.map((appointment) => (
            <li key={appointment.id} className="space-y-2 px-4 py-3 text-sm">
              <div className="flex items-start justify-between gap-3">
                <PatientSummary patient={appointment.patient} />
                <span className="shrink-0 text-right text-xs text-slate-500">
                  {formatShortDate(appointment.scheduled_at.slice(0, 10))} · {formatTime(appointment.scheduled_at)}
                  <br />
                  {appointment.provider?.display_name}
                </span>
              </div>
              <p className="text-xs text-slate-600">{appointment.reason}</p>
              <ReviewActions appointment={appointment} onReviewed={handleReviewed} />
            </li>
          ))}
        </ul>
      )}

      {tab === 'review' ? (
        <div className="hidden overflow-x-auto rounded-b-lg rounded-tr-lg border border-slate-200 bg-white shadow-sm sm:block">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-4 py-3">When</th>
                <th className="px-4 py-3">With</th>
                <th className="px-4 py-3">Patient</th>
                <th className="px-4 py-3">Reason</th>
                <th className="px-4 py-3">Decision</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {pending.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                    No bookings are waiting for review.
                  </td>
                </tr>
              )}
              {pending.map((appointment) => (
                <tr key={appointment.id} className="align-top">
                  <td className="whitespace-nowrap px-4 py-3">
                    <div className="font-medium text-slate-900">{formatShortDate(appointment.scheduled_at.slice(0, 10))}</div>
                    <div className="text-xs text-slate-500">{formatTime(appointment.scheduled_at)}</div>
                  </td>
                  <td className="px-4 py-3 text-slate-700">{appointment.provider?.display_name ?? '—'}</td>
                  <td className="px-4 py-3">
                    <PatientSummary patient={appointment.patient} />
                  </td>
                  <td className="px-4 py-3 text-slate-600">{appointment.reason}</td>
                  <td className="px-4 py-3">
                    <ReviewActions appointment={appointment} onReviewed={handleReviewed} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <>
          <div className="mt-4 flex flex-wrap items-center gap-3">
            <label htmlFor="appointments-date" className="text-sm font-medium text-slate-700">
              Date
            </label>
            <input
              id="appointments-date"
              type="date"
              value={date}
              onChange={(e) => setDate(e.target.value)}
              className="rounded border border-slate-300 px-3 py-2 text-sm"
            />
            <label htmlFor="appointments-provider" className="text-sm font-medium text-slate-700">
              With
            </label>
            <select
              id="appointments-provider"
              value={providerId}
              onChange={(e) => setProviderId(e.target.value)}
              className="rounded border border-slate-300 px-3 py-2 text-sm"
            >
              <option value="">All professionals</option>
              {providers.map((provider) => (
                <option key={provider.id} value={provider.id}>
                  {provider.display_name}
                  {provider.job_title ? ` (${provider.job_title})` : ''}
                </option>
              ))}
            </select>
          </div>

          <div className="mt-4 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table className="w-full min-w-[760px] text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-4 py-3">Time</th>
                  <th className="px-4 py-3">With</th>
                  <th className="px-4 py-3">Patient</th>
                  <th className="px-4 py-3">Reason</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isLoading && (
                  <tr>
                    <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                      Loading…
                    </td>
                  </tr>
                )}
                {!isLoading && appointments.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                      No appointments on this date.
                    </td>
                  </tr>
                )}
                {!isLoading &&
                  appointments.map((appointment) => (
                    <tr key={appointment.id} className="align-top">
                      <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{formatTime(appointment.scheduled_at)}</td>
                      <td className="px-4 py-3 text-slate-700">{appointment.provider?.display_name ?? 'Nurse'}</td>
                      <td className="px-4 py-3">
                        <PatientSummary patient={appointment.patient} />
                        {appointment.patient.contact_no && (
                          <div className="text-xs text-slate-400">{appointment.patient.contact_no}</div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {appointment.reason}
                        {appointment.review_note && <div className="text-xs text-slate-400">Note: {appointment.review_note}</div>}
                        {appointment.cancel_reason && <div className="text-xs text-slate-400">Cancelled: {appointment.cancel_reason}</div>}
                      </td>
                      <td className="px-4 py-3">
                        <StatusPill tone={APPOINTMENT_STATUS_TONES[appointment.status]}>
                          {APPOINTMENT_STATUS_LABELS[appointment.status]}
                        </StatusPill>
                      </td>
                      <td className="px-4 py-3">
                        {appointment.status === 'pending' && !isDue(appointment) && (
                          <ReviewActions appointment={appointment} onReviewed={handleReviewed} />
                        )}
                        <div className="flex flex-wrap gap-2">
                          {canCheckIn(appointment) && (
                            <button
                              type="button"
                              disabled={busyId === appointment.id}
                              onClick={() => handleCheckIn(appointment)}
                              className="rounded bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                            >
                              Check in
                            </button>
                          )}
                          {canMarkNoShow(appointment) && (
                            <button
                              type="button"
                              disabled={busyId === appointment.id}
                              onClick={() => handleMarkNoShow(appointment)}
                              className="rounded bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-200 disabled:opacity-50"
                            >
                              Mark No-show
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}
