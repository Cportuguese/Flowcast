import { Fragment, useState, type FormEvent } from 'react'
import { api } from '../lib/api'
import { CertificatePanel } from '../components/CertificatePanel'
import { PageHeader } from '../components/PageHeader'
import { PatientSummary } from '../components/PatientSummary'
import { useAuth } from '../context/AuthContext'
import type { Certificate } from '../lib/certificates'
import { formatTime } from '../lib/dates'
import { useDepartments } from '../lib/departments'
import { extractErrorMessage } from '../lib/errors'
import { buttonClass } from '../components/Button'

interface Visit {
  id: number
  queue_entry_id: number | null
  appointment_id: number | null
  date: string
  patient: {
    id: number
    name: string
    school_id: string | null
    id_verified: boolean
    department: string | null
    course: string | null
    birthdate: string | null
    sex: 'male' | 'female' | null
  }
  created_by: string
  created_by_id: number
  created_by_title: string | null
  certificate: Certificate | null
  complaint: string
  vitals: { blood_pressure: string | null; temperature: string | null; pulse: number | null; resp_rate: number | null }
  intervention: string
  remarks: string | null
  referred_to: string | null
  medicines: Array<{ id: number; name: string; unit: string; quantity: number }>
}

interface Filters {
  name: string
  middle_name: string
  birthdate: string
  school_id: string
  department: string
  course: string
  date: string
}

const EMPTY_FILTERS: Filters = { name: '', middle_name: '', birthdate: '', school_id: '', department: '', course: '', date: '' }

/** The API returns at most this many, newest first. */
const RESULT_LIMIT = 200

const inputClass =
  'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100'

const labelClass = 'mb-1 block text-xs font-medium text-slate-700'

/**
 * Visit history for any patient, searched by who they are (name, middle
 * name, birthdate, ID number, department or course) and/or the visit date.
 */
export function VisitsSearch() {
  const { user } = useAuth()
  const departments = useDepartments()
  const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS)
  const [results, setResults] = useState<Visit[] | null>(null)
  const [expandedId, setExpandedId] = useState<number | null>(null)
  const [isSearching, setIsSearching] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const courses = departments.find((dept) => dept.code === filters.department)?.courses ?? departments.flatMap((dept) => dept.courses)

  function update(patch: Partial<Filters>) {
    setFilters((current) => ({ ...current, ...patch }))
  }

  // The head nurse may issue for any visit; the dentist and physician for
  // the visits they recorded.
  function canIssueFor(visit: Visit) {
    return user?.role === 'admin' || (user?.role === 'personnel' && visit.created_by_id === user.id)
  }

  async function handleSearch(event: FormEvent) {
    event.preventDefault()
    setError(null)

    const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value.trim() !== ''))

    if (Object.keys(params).length === 0) {
      setError('Enter at least one thing to search by: a name, birthdate, ID number, department or date.')
      return
    }

    setIsSearching(true)
    try {
      const response = await api.get<Visit[]>('/admin/visits/search', { params })
      setResults(response.data)
      setExpandedId(null)
    } catch (err) {
      setError(extractErrorMessage(err, 'Search failed.'))
    } finally {
      setIsSearching(false)
    }
  }

  function handleClear() {
    setFilters(EMPTY_FILTERS)
    setResults(null)
    setError(null)
  }

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader title="Visit Records" />

      <form onSubmit={handleSearch} className="mt-6 grid gap-3 rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <div className="sm:col-span-2">
          <label htmlFor="visits-name" className={labelClass}>Name</label>
          <input
            id="visits-name"
            value={filters.name}
            onChange={(e) => update({ name: e.target.value })}
            placeholder="First, last or full name"
            className={inputClass}
          />
        </div>
        <div>
          <label htmlFor="visits-middle" className={labelClass}>Middle name</label>
          <input id="visits-middle" value={filters.middle_name} onChange={(e) => update({ middle_name: e.target.value })} className={inputClass} />
        </div>
        <div>
          <label htmlFor="visits-birthdate" className={labelClass}>Date of birth</label>
          <input id="visits-birthdate" type="date" value={filters.birthdate} onChange={(e) => update({ birthdate: e.target.value })} className={inputClass} />
        </div>
        <div>
          <label htmlFor="visits-school-id" className={labelClass}>ID number</label>
          <input
            id="visits-school-id"
            value={filters.school_id}
            onChange={(e) => update({ school_id: e.target.value })}
            placeholder="e.g. 23-1057-651"
            className={inputClass}
          />
        </div>
        <div>
          <label htmlFor="visits-department" className={labelClass}>Department</label>
          <select
            id="visits-department"
            value={filters.department}
            onChange={(e) => update({ department: e.target.value, course: '' })}
            className={inputClass}
          >
            <option value="">Any department</option>
            {departments.map((dept) => (
              <option key={dept.code} value={dept.code}>
                {dept.code} — {dept.name}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="visits-course" className={labelClass}>Course</label>
          <select id="visits-course" value={filters.course} onChange={(e) => update({ course: e.target.value })} className={inputClass}>
            <option value="">Any course</option>
            {courses.map((course) => (
              <option key={course.code} value={course.code}>
                {course.code} — {course.name}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="visits-date" className={labelClass}>Visit date</label>
          <input id="visits-date" type="date" value={filters.date} onChange={(e) => update({ date: e.target.value })} className={inputClass} />
        </div>
        <div className="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
          <button type="submit" disabled={isSearching} className={buttonClass.primary}>
            {isSearching ? 'Searching…' : 'Search'}
          </button>
          <button type="button" onClick={handleClear} className={buttonClass.ghost}>
            Clear
          </button>
        </div>
      </form>

      {error && (
        <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
      )}

      {results && results.length >= RESULT_LIMIT && (
        <p className="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Showing the newest {RESULT_LIMIT} visits. Add another filter to narrow the search.
        </p>
      )}

      {results && (
        <div className="mt-6 overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-4 py-3">Date</th>
                <th className="px-4 py-3">Patient</th>
                <th className="px-4 py-3">Complaint</th>
                <th className="px-4 py-3">Intervention</th>
                <th className="px-4 py-3">Seen by</th>
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
                    onClick={() => setExpandedId(expandedId === visit.id ? null : visit.id)}
                    className="cursor-pointer align-top hover:bg-slate-50"
                  >
                    <td className="whitespace-nowrap px-4 py-3">
                      <div className="text-slate-700">{new Date(visit.date).toLocaleDateString()}</div>
                      <div className="text-xs text-slate-400">{formatTime(visit.date)}</div>
                    </td>
                    <td className="px-4 py-3">
                      <PatientSummary patient={visit.patient} />
                    </td>
                    <td className="px-4 py-3 text-slate-600">{visit.complaint}</td>
                    <td className="px-4 py-3 text-slate-600">{visit.intervention}</td>
                    <td className="px-4 py-3 text-slate-600">
                      {visit.created_by}
                      {visit.created_by_title && <div className="text-xs text-slate-400">{visit.created_by_title}</div>}
                    </td>
                  </tr>
                  {expandedId === visit.id && (
                    <tr>
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
                            <p className="text-xs font-medium uppercase text-slate-500">Referred to</p>
                            <p className="text-sm text-slate-700">{visit.referred_to ?? '—'}</p>
                          </div>
                          <div className="sm:col-span-2">
                            <p className="text-xs font-medium uppercase text-slate-500">Remarks</p>
                            <p className="text-sm text-slate-700">{visit.remarks ?? '—'}</p>
                          </div>
                          {visit.medicines.length > 0 && (
                            <div className="sm:col-span-2">
                              <p className="text-xs font-medium uppercase text-slate-500">Medicines given</p>
                              <p className="text-sm text-slate-700">
                                {visit.medicines.map((m) => `${m.name} × ${m.quantity} ${m.unit}`).join(', ')}
                              </p>
                            </div>
                          )}
                          <div className="sm:col-span-2">
                            <CertificatePanel
                              visitId={visit.id}
                              certificate={visit.certificate}
                              canIssue={canIssueFor(visit)}
                              onIssued={(certificate) =>
                                setResults((rows) =>
                                  rows?.map((row) => (row.id === visit.id ? { ...row, certificate } : row)) ?? rows,
                                )
                              }
                            />
                          </div>
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
  )
}
