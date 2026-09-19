import axios from 'axios'
import { useNavigate } from 'react-router-dom'
import { Fragment, useState, type FormEvent } from 'react'
import { useAuth } from '../context/AuthContext'
import { api } from '../lib/api'

interface Visit {
  id: number
  queue_entry_id: number
  date: string
  patient: { id: number; name: string; school_id: string | null }
  created_by: string
  complaint: string
  vitals: { blood_pressure: string | null; temperature: string | null; pulse: number | null; resp_rate: number | null }
  diagnosis: string
  treatment: string | null
  referred_to: string | null
  medicines: Array<{ id: number; name: string; unit: string; quantity: number }>
}

export function VisitsSearch() {
  const { logout } = useAuth()
  const navigate = useNavigate()
  const [schoolId, setSchoolId] = useState('')
  const [date, setDate] = useState('')
  const [results, setResults] = useState<Visit[] | null>(null)
  const [expandedId, setExpandedId] = useState<number | null>(null)
  const [isSearching, setIsSearching] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSearch(event: FormEvent) {
    event.preventDefault()
    setError(null)

    if (!schoolId && !date) {
      setError('Enter a school ID or a date to search.')
      return
    }

    setIsSearching(true)
    try {
      const response = await api.get<Visit[]>('/admin/visits/search', {
        params: { school_id: schoolId || undefined, date: date || undefined },
      })
      setResults(response.data)
      setExpandedId(null)
    } catch (err) {
      if (axios.isAxiosError(err) && err.response) {
        const data = err.response.data as { message?: string }
        setError(data.message ?? 'Search failed.')
      } else {
        setError('Search failed.')
      }
    } finally {
      setIsSearching(false)
    }
  }

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  return (
    <div className="min-h-screen bg-gray-50 p-8">
      <div className="mx-auto max-w-4xl">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-semibold text-gray-900">Visit Records</h1>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => navigate('/dashboard')}
              className="rounded bg-gray-200 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-300"
            >
              Back
            </button>
            <button
              type="button"
              onClick={handleLogout}
              className="rounded bg-gray-200 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-300"
            >
              Log out
            </button>
          </div>
        </div>

        <form onSubmit={handleSearch} className="mt-6 flex flex-wrap items-end gap-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">School ID</label>
            <input
              value={schoolId}
              onChange={(e) => setSchoolId(e.target.value)}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-700">Date</label>
            <input
              type="date"
              value={date}
              onChange={(e) => setDate(e.target.value)}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
          </div>
          <button
            type="submit"
            disabled={isSearching}
            className="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {isSearching ? 'Searching…' : 'Search'}
          </button>
        </form>

        {error && (
          <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
        )}

        {results && (
          <div className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-4 py-3">Date</th>
                  <th className="px-4 py-3">Patient</th>
                  <th className="px-4 py-3">Complaint</th>
                  <th className="px-4 py-3">Diagnosis</th>
                  <th className="px-4 py-3">Referred to</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {results.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                      No visits found.
                    </td>
                  </tr>
                )}
                {results.map((visit) => (
                  <Fragment key={visit.id}>
                    <tr
                      key={visit.id}
                      onClick={() => setExpandedId(expandedId === visit.id ? null : visit.id)}
                      className="cursor-pointer hover:bg-slate-50"
                    >
                      <td className="whitespace-nowrap px-4 py-3">
                        <div className="text-slate-700">{new Date(visit.date).toLocaleDateString()}</div>
                        <div className="text-xs text-slate-400">
                          {new Date(visit.date).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                        </div>
                      </td>
                      <td className="px-4 py-3 font-medium text-slate-900">{visit.patient.name}</td>
                      <td className="px-4 py-3 text-slate-600">{visit.complaint}</td>
                      <td className="px-4 py-3 text-slate-600">{visit.diagnosis}</td>
                      <td className="px-4 py-3 text-slate-600">{visit.referred_to ?? '—'}</td>
                    </tr>
                    {expandedId === visit.id && (
                      <tr key={`${visit.id}-detail`}>
                        <td colSpan={5} className="bg-slate-50 px-4 py-4">
                          <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                              <p className="text-xs font-medium uppercase text-slate-500">Vitals</p>
                              <p className="text-sm text-slate-700">
                                BP {visit.vitals.blood_pressure ?? '—'} · Temp {visit.vitals.temperature ?? '—'}°C ·
                                Pulse {visit.vitals.pulse ?? '—'} · RR {visit.vitals.resp_rate ?? '—'}
                              </p>
                            </div>
                            <div>
                              <p className="text-xs font-medium uppercase text-slate-500">Seen by</p>
                              <p className="text-sm text-slate-700">{visit.created_by}</p>
                            </div>
                            <div className="sm:col-span-2">
                              <p className="text-xs font-medium uppercase text-slate-500">Treatment</p>
                              <p className="text-sm text-slate-700">{visit.treatment ?? '—'}</p>
                            </div>
                            {visit.medicines.length > 0 && (
                              <div className="sm:col-span-2">
                                <p className="text-xs font-medium uppercase text-slate-500">Medicines given</p>
                                <p className="text-sm text-slate-700">
                                  {visit.medicines.map((m) => `${m.name} × ${m.quantity} ${m.unit}`).join(', ')}
                                </p>
                              </div>
                            )}
                          </div>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
