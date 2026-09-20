import axios from 'axios'
import { useState, type FormEvent } from 'react'
import { api } from '../lib/api'
import { PageHeader } from '../components/PageHeader'
import { buttonClass } from '../components/Button'

interface AuditLogEntry {
  id: number
  user: { id: number; name: string; role: string } | null
  action: string
  model_type: string | null
  model_id: number | null
  changes: Record<string, unknown> | null
  created_at: string
}

function extractErrorMessage(err: unknown, fallback: string) {
  if (axios.isAxiosError(err) && err.response) {
    const data = err.response.data as { message?: string }
    return data.message ?? fallback
  }
  return fallback
}

export function AuditLog() {

  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [userId, setUserId] = useState('')
  const [action, setAction] = useState('')
  const [patient, setPatient] = useState('')

  const [results, setResults] = useState<AuditLogEntry[] | null>(null)
  const [isSearching, setIsSearching] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSearch(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsSearching(true)
    try {
      const response = await api.get<AuditLogEntry[]>('/admin/audit-logs', {
        params: {
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
          user_id: userId || undefined,
          action: action || undefined,
          patient: patient || undefined,
        },
      })
      setResults(response.data)
    } catch (err) {
      setError(extractErrorMessage(err, 'Search failed.'))
    } finally {
      setIsSearching(false)
    }
  }

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader title="Audit Log" />

      <form onSubmit={handleSearch} className="mt-6 flex flex-wrap items-end gap-3">
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">From</label>
          <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">To</label>
          <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">User ID</label>
          <input value={userId} onChange={(e) => setUserId(e.target.value)} className="w-24 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">Action</label>
          <input placeholder="e.g. queue" value={action} onChange={(e) => setAction(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-700">Patient (ID or name)</label>
          <input value={patient} onChange={(e) => setPatient(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <button
          type="submit"
          disabled={isSearching}
          className={buttonClass.primary}
        >
          {isSearching ? 'Searching…' : 'Search'}
        </button>
      </form>

      {error && <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

      {results && (
        <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-4 py-3">Date/Time</th>
                <th className="px-4 py-3">User</th>
                <th className="px-4 py-3">Action</th>
                <th className="px-4 py-3">Model</th>
                <th className="px-4 py-3">Changes</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {results.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-4 py-6 text-center text-slate-400">No activity found.</td>
                </tr>
              )}
              {results.map((log) => (
                <tr key={log.id}>
                  <td className="px-4 py-3 text-slate-500">{new Date(log.created_at).toLocaleString()}</td>
                  <td className="px-4 py-3 text-slate-700">
                    {log.user ? `${log.user.name} (${log.user.role})` : 'System'}
                  </td>
                  <td className="px-4 py-3 font-medium text-slate-900">{log.action}</td>
                  <td className="px-4 py-3 text-slate-600">
                    {log.model_type ? `${log.model_type} #${log.model_id}` : '—'}
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {log.changes ? (
                      <ul className="space-y-0.5">
                        {Object.entries(log.changes).map(([key, value]) => (
                          <li key={key}>
                            <span className="text-slate-400">{key}:</span> {String(value ?? '—')}
                          </li>
                        ))}
                      </ul>
                    ) : (
                      '—'
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
