import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { VisitFormModal } from '../components/VisitFormModal'
import { api } from '../lib/api'
import { extractErrorMessage } from '../lib/errors'
import {
  QUEUE_STATUS_LABELS,
  QUEUE_STATUS_TONES,
  type QueueEntry,
} from '../lib/queue'
import { timeInQueue, useTodayQueue } from '../lib/useTodayQueue'
import { PageHeader } from '../components/PageHeader'
import { StatusPill } from '../components/StatusPill'
import { buttonClass } from '../components/Button'

export function QueueBoard() {
  const {
    date,
    entries,
    waiting,
    inConsultation,
    isLoading,
    actionError,
    setActionError,
    isCallingNext,
    busyEntryId,
    callNext,
    complete,
    skip,
  } = useTodayQueue()
  const [searchParams] = useSearchParams()

  const [statusFilter, setStatusFilter] = useState<'all' | QueueEntry['status']>('all')
  const [visitFormEntry, setVisitFormEntry] = useState<QueueEntry | null>(null)

  const [showWalkInForm, setShowWalkInForm] = useState(() => searchParams.get('walkin') === '1')
  const [walkInName, setWalkInName] = useState('')
  const [walkInContact, setWalkInContact] = useState('')
  const [walkInType, setWalkInType] = useState<'' | 'student' | 'faculty' | 'staff'>('')
  const [isSubmittingWalkIn, setIsSubmittingWalkIn] = useState(false)

  async function handleWalkInSubmit(event: FormEvent) {
    event.preventDefault()
    setActionError(null)
    setIsSubmittingWalkIn(true)

    try {
      await api.post('/admin/queue/walk-in', {
        name: walkInName,
        contact_no: walkInContact || null,
        patient_type: walkInType || null,
      })
      setWalkInName('')
      setWalkInContact('')
      setWalkInType('')
      setShowWalkInForm(false)
    } catch (err) {
      setActionError(extractErrorMessage(err, 'Unable to register this walk-in.'))
    } finally {
      setIsSubmittingWalkIn(false)
    }
  }

  const visibleEntries = statusFilter === 'all' ? entries : entries.filter((e) => e.status === statusFilter)

  return (
    <>
      <div className="mx-auto max-w-4xl">
        <PageHeader title="Live Queue" subtitle={date ?? '—'} />

        {actionError && (
          <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{actionError}</p>
        )}

        <div className="mt-6 flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={callNext}
            disabled={isCallingNext || waiting.length === 0}
            className={buttonClass.primary}
          >
            {isCallingNext ? 'Calling…' : `Call Next (${waiting.length} waiting)`}
          </button>
          <button
            type="button"
            onClick={() => setShowWalkInForm((v) => !v)}
            className={buttonClass.outline}
          >
            {showWalkInForm ? 'Cancel walk-in' : 'Register walk-in'}
          </button>
          {inConsultation.length > 0 && (
            <span className="text-sm text-slate-500">
              Currently serving: {inConsultation.map((e) => `#${e.queue_number} ${e.patient.name}`).join(', ')}
            </span>
          )}
        </div>

        {showWalkInForm && (
          <form
            onSubmit={handleWalkInSubmit}
            className="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2"
          >
            <div className="sm:col-span-2">
              <h2 className="text-sm font-semibold text-slate-900">Walk-in registration</h2>
              <p className="text-xs text-slate-500">For patients who don't have the app.</p>
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700" htmlFor="walkin-name">
                Full name
              </label>
              <input
                id="walkin-name"
                required
                value={walkInName}
                onChange={(event) => setWalkInName(event.target.value)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700" htmlFor="walkin-contact">
                Contact number
              </label>
              <input
                id="walkin-contact"
                value={walkInContact}
                onChange={(event) => setWalkInContact(event.target.value)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-700" htmlFor="walkin-type">
                Patient type
              </label>
              <select
                id="walkin-type"
                value={walkInType}
                onChange={(event) => setWalkInType(event.target.value as typeof walkInType)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
              >
                <option value="">—</option>
                <option value="student">Student</option>
                <option value="faculty">Faculty</option>
                <option value="staff">Staff</option>
              </select>
            </div>
            <div className="flex items-end">
              <button
                type="submit"
                disabled={isSubmittingWalkIn}
                className="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-50"
              >
                {isSubmittingWalkIn ? 'Checking in…' : 'Check in'}
              </button>
            </div>
          </form>
        )}

        <div className="mt-6 flex gap-1 border-b border-slate-200">
          {(['all', 'waiting', 'in_consultation', 'done', 'skipped'] as const).map((tab) => (
            <button
              key={tab}
              type="button"
              onClick={() => setStatusFilter(tab)}
              className={`rounded-t-lg px-4 py-2 text-sm font-medium ${
                statusFilter === tab
                  ? 'border border-b-white bg-white text-slate-900'
                  : 'text-slate-500 hover:text-slate-700'
              }`}
            >
              {tab === 'all' ? 'All' : QUEUE_STATUS_LABELS[tab]}
            </button>
          ))}
        </div>

        <div className="overflow-hidden rounded-b-lg rounded-tr-lg border border-slate-200 bg-white shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-4 py-3">#</th>
                <th className="px-4 py-3">Patient</th>
                <th className="px-4 py-3">Source</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Called</th>
                <th className="px-4 py-3">Time in queue</th>
                <th className="px-4 py-3">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {isLoading && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                    Loading queue…
                  </td>
                </tr>
              )}
              {!isLoading && visibleEntries.length === 0 && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                    {entries.length === 0 ? 'No one has checked in today yet.' : 'No entries match this filter.'}
                  </td>
                </tr>
              )}
              {visibleEntries.map((entry) => (
                <tr key={entry.id}>
                  <td className="px-4 py-3 font-semibold text-slate-900">{entry.queue_number}</td>
                  <td className="px-4 py-3">
                    <div className="font-medium text-slate-900">{entry.patient.name}</div>
                    {entry.patient.contact_no && (
                      <div className="text-xs text-slate-500">{entry.patient.contact_no}</div>
                    )}
                  </td>
                  <td className="px-4 py-3 capitalize text-slate-600">{entry.source.replace('_', ' ')}</td>
                  <td className="px-4 py-3">
                    <StatusPill tone={QUEUE_STATUS_TONES[entry.status]}>
                      {QUEUE_STATUS_LABELS[entry.status]}
                    </StatusPill>
                  </td>
                  <td className="px-4 py-3 text-slate-500">
                    {entry.called_at ? new Date(entry.called_at).toLocaleTimeString() : '—'}
                  </td>
                  <td className="px-4 py-3 text-slate-500">{timeInQueue(entry)}</td>
                  <td className="px-4 py-3">
                    {entry.status === 'in_consultation' && (
                      <div className="flex gap-2">
                        <button
                          type="button"
                          disabled={busyEntryId === entry.id}
                          onClick={() => complete(entry).then((ok) => ok && setVisitFormEntry(entry))}
                          className="rounded bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                        >
                          Mark Done
                        </button>
                        <button
                          type="button"
                          disabled={busyEntryId === entry.id}
                          onClick={() => skip(entry.id)}
                          className="rounded bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-200 disabled:opacity-50"
                        >
                          Mark Skipped
                        </button>
                      </div>
                    )}
                    {entry.status === 'waiting' && (
                      <button
                        type="button"
                        disabled={busyEntryId === entry.id}
                        onClick={() => skip(entry.id)}
                        className="rounded bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-200 disabled:opacity-50"
                      >
                        Mark Skipped
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
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
