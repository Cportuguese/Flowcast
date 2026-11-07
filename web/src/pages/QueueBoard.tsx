import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { PatientSummary } from '../components/PatientSummary'
import { VisitFormModal } from '../components/VisitFormModal'
import { WalkInPanel } from '../components/WalkInPanel'
import { formatTime } from '../lib/dates'
import {
  QUEUE_STATUS_LABELS,
  QUEUE_STATUS_TONES,
  isDueBooking,
  isResolved,
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
    isCallingNext,
    busyEntryId,
    callNext,
    complete,
    skip,
  } = useTodayQueue()
  const [searchParams] = useSearchParams()

  const [statusFilter, setStatusFilter] = useState<'all' | QueueEntry['status']>('all')
  const [search, setSearch] = useState('')
  const [visitFormEntry, setVisitFormEntry] = useState<QueueEntry | null>(null)
  const [showWalkInForm, setShowWalkInForm] = useState(() => searchParams.get('walkin') === '1')

  const needle = search.trim().toLowerCase()
  // Finished entries sink below the active ones, so on a busy day the
  // patients still to be seen stay at the top instead of under a growing
  // pile of done tickets. Sorting is stable, so each group keeps its
  // queue-number order.
  const visibleEntries = entries
    .filter(
      (e) =>
        (statusFilter === 'all' || e.status === statusFilter) &&
        (!needle ||
          e.patient.name.toLowerCase().includes(needle) ||
          String(e.queue_number) === needle ||
          (e.patient.school_id ?? '').startsWith(needle)),
    )
    .sort((a, b) => Number(isResolved(a)) - Number(isResolved(b)))

  return (
    <>
      <div className="mx-auto max-w-5xl">
        <PageHeader title="Live Queue" subtitle={date ?? '—'} />

        {actionError && (
          <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{actionError}</p>
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
            {showWalkInForm ? 'Close walk-in' : 'Register walk-in'}
          </button>
          {inConsultation.length > 0 && (
            <span className="text-sm text-slate-500">
              Currently serving: {inConsultation.map((e) => `#${e.queue_number} ${e.patient.name}`).join(', ')}
            </span>
          )}
        </div>

        {showWalkInForm && <WalkInPanel onCheckedIn={() => setShowWalkInForm(false)} />}

        <div className="mt-6 flex flex-wrap items-end gap-1 border-b border-slate-200">
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
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Find by name, # or ID"
            aria-label="Find patient by name, queue number or ID number"
            className="mb-1.5 ml-auto w-52 rounded-lg border border-slate-300 px-3 py-1.5 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
          />
        </div>

        <div className="overflow-x-auto rounded-b-lg rounded-tr-lg border border-slate-200 bg-white shadow-sm">
          <table className="w-full min-w-[720px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-4 py-3">#</th>
                <th className="px-4 py-3">Patient</th>
                <th className="px-4 py-3">Came as</th>
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
                    {entries.length === 0 ? 'No one has checked in today yet.' : 'No entries match this search.'}
                  </td>
                </tr>
              )}
              {visibleEntries.map((entry) => (
                <tr key={entry.id}>
                  <td className="px-4 py-3 font-semibold text-slate-900">{entry.queue_number}</td>
                  <td className="px-4 py-3">
                    <PatientSummary patient={entry.patient} />
                    {entry.patient.contact_no && <div className="text-xs text-slate-400">{entry.patient.contact_no}</div>}
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {entry.booked_for ? (
                      <StatusPill tone={entry.status === 'waiting' && isDueBooking(entry) ? 'info' : 'neutral'}>
                        Booked {formatTime(entry.booked_for)}
                      </StatusPill>
                    ) : (
                      'Walk-in'
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <StatusPill tone={QUEUE_STATUS_TONES[entry.status]}>
                      {QUEUE_STATUS_LABELS[entry.status]}
                    </StatusPill>
                  </td>
                  <td className="px-4 py-3 text-slate-500">{entry.called_at ? formatTime(entry.called_at) : '—'}</td>
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
