import axios from 'axios'
import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../components/PageHeader'
import { StatusPill } from '../components/StatusPill'
import { StatTile } from '../components/StatTile'
import { api } from '../lib/api'
import {
  QUEUE_STATUS_LABELS,
  QUEUE_STATUS_TONES,
  minutesBetween,
  type QueueEntry,
} from '../lib/queue'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function formatTime(iso: string | null) {
  return iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'
}

function formatMinutes(minutes: number | null) {
  return minutes === null ? '—' : `${minutes} min`
}

export function QueueHistory() {
  const [date, setDate] = useState(todayIso)
  const [entries, setEntries] = useState<QueueEntry[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async (day: string) => {
    setIsLoading(true)
    setError(null)
    try {
      const response = await api.get<{ date: string; entries: QueueEntry[] }>(`/queue/${day}/history`)
      setEntries(response.data.entries)
    } catch (err) {
      setEntries([])
      setError(
        axios.isAxiosError(err) && err.response?.data?.message
          ? String(err.response.data.message)
          : 'Unable to load queue history.',
      )
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(date)
  }, [date, load])

  const done = entries.filter((e) => e.status === 'done')
  const skipped = entries.filter((e) => e.status === 'skipped').length
  const waits = done.map((e) => minutesBetween(e.joined_at, e.called_at)).filter((m): m is number => m !== null)
  const avgWait = waits.length ? Math.round(waits.reduce((a, b) => a + b, 0) / waits.length) : null

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader
        title="Queue History"
        subtitle="Full queue for a past day, including who handled each ticket."
        actions={
          <input
            type="date"
            value={date}
            max={todayIso()}
            onChange={(e) => e.target.value && setDate(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
          />
        }
      />

      {error && <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

      <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <StatTile label="Total" value={entries.length} />
        <StatTile label="Completed" value={done.length} />
        <StatTile label="Skipped" value={skipped} />
        <StatTile label="Avg wait" value={avgWait === null ? '—' : `${avgWait} min`} />
      </div>

      <div className="mt-6 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3">#</th>
              <th className="px-4 py-3">Patient</th>
              <th className="px-4 py-3">Source</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Joined</th>
              <th className="px-4 py-3">Called</th>
              <th className="px-4 py-3">Completed</th>
              <th className="px-4 py-3">Wait</th>
              <th className="px-4 py-3">Consult</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={9} className="px-4 py-6 text-center text-slate-400">
                  Loading…
                </td>
              </tr>
            )}
            {!isLoading && entries.length === 0 && (
              <tr>
                <td colSpan={9} className="px-4 py-6 text-center text-slate-400">
                  No queue entries on this date.
                </td>
              </tr>
            )}
            {entries.map((entry) => (
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
                <td className="px-4 py-3 text-slate-500">{formatTime(entry.joined_at)}</td>
                <td className="px-4 py-3 text-slate-500">{formatTime(entry.called_at)}</td>
                <td className="px-4 py-3 text-slate-500">{formatTime(entry.completed_at)}</td>
                <td className="px-4 py-3 text-slate-500">
                  {formatMinutes(minutesBetween(entry.joined_at, entry.called_at))}
                </td>
                <td className="px-4 py-3 text-slate-500">
                  {formatMinutes(minutesBetween(entry.called_at, entry.completed_at))}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

