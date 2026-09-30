import { useState, type FormEvent } from 'react'
import { api } from '../lib/api'
import { downloadCsv } from '../lib/csv'
import { todayIso } from '../lib/dates'
import { extractErrorMessage } from '../lib/errors'
import { PageHeader } from '../components/PageHeader'
import { buttonClass } from '../components/Button'

interface QueueSummaryDay {
  date: string | null
  total_entries: number
  walk_in: number
  appointment: number
  done: number
  skipped: number
  avg_wait_minutes: number | null
  avg_consult_minutes: number | null
}

interface QueueSummaryResponse {
  date_from: string
  date_to: string
  days: QueueSummaryDay[]
  totals: QueueSummaryDay
}

interface VisitSummaryResponse {
  date_from: string
  date_to: string
  total_visits: number
  referred_count: number
  by_day: Array<{ date: string; count: number }>
  top_diagnoses: Array<{ diagnosis: string; count: number }>
}

interface NoShowSummaryResponse {
  month: string
  total_no_shows: number
  total_appointments: number
  no_show_rate: number
  by_day: Array<{ date: string; no_show_count: number; appointment_count: number }>
  appointments: Array<{ id: number; patient: { id: number; name: string }; scheduled_at: string; reason: string }>
}

interface PeakHoursByWeekdayResponse {
  weekdays: Array<{
    day_of_week: number
    label: string
    hours: Array<{ hour: number; avg_arrivals: number }>
    peak_hour: number | null
  }>
}

export function Reports() {

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader title="Reports" className="print:hidden" />

      <QueueSummarySection />
      <VisitSummarySection />
      <NoShowSummarySection />
      <PeakHoursByWeekdaySection />
    </div>
  )
}

function ReportActions({ onCsv }: { onCsv: () => void }) {
  return (
    <div className="flex gap-2 print:hidden">
      <button
        type="button"
        onClick={onCsv}
        className="rounded bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200"
      >
        Download CSV
      </button>
      <button
        type="button"
        onClick={() => window.print()}
        className="rounded bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200"
      >
        Print / Export PDF
      </button>
    </div>
  )
}

function QueueSummarySection() {
  const [dateFrom, setDateFrom] = useState(todayIso())
  const [dateTo, setDateTo] = useState(todayIso())
  const [data, setData] = useState<QueueSummaryResponse | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsLoading(true)
    try {
      const response = await api.get<QueueSummaryResponse>('/admin/reports/queue-summary', {
        params: { date_from: dateFrom, date_to: dateTo },
      })
      setData(response.data)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to load the queue summary.'))
    } finally {
      setIsLoading(false)
    }
  }

  function handleCsv() {
    if (!data) return
    const header = ['Date', 'Total', 'Walk-in', 'Appointment', 'Done', 'Skipped', 'Avg wait (min)', 'Avg consult (min)']
    const rows = data.days.map((d) => [d.date, d.total_entries, d.walk_in, d.appointment, d.done, d.skipped, d.avg_wait_minutes, d.avg_consult_minutes])
    const totals = ['Total', data.totals.total_entries, data.totals.walk_in, data.totals.appointment, data.totals.done, data.totals.skipped, data.totals.avg_wait_minutes, data.totals.avg_consult_minutes]
    downloadCsv(`queue-summary-${data.date_from}-to-${data.date_to}.csv`, [header, ...rows, totals])
  }

  return (
    <section className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm print:mb-8 print:border-0 print:shadow-none">
      <div className="border-b border-slate-200 px-5 py-4">
        <h2 className="text-sm font-semibold text-slate-900">Queue summary</h2>
        <p className="text-xs text-slate-500">All queue entries within a date range.</p>
      </div>

      <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-3 border-b border-slate-100 p-5 print:hidden">
        <div>
          <label htmlFor="report-from" className="mb-1 block text-xs font-medium text-slate-700">From</label>
          <input id="report-from" type="date" required value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="rounded border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label htmlFor="report-to" className="mb-1 block text-xs font-medium text-slate-700">To</label>
          <input id="report-to" type="date" required value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="rounded border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <button type="submit" disabled={isLoading} className={buttonClass.primary}>
          {isLoading ? 'Running…' : 'Run'}
        </button>
        {data && <ReportActions onCsv={handleCsv} />}
      </form>

      {error && <p className="px-5 py-3 text-sm text-red-700">{error}</p>}

      {data && (
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3">Date</th>
              <th className="px-4 py-3">Total</th>
              <th className="px-4 py-3">Walk-in</th>
              <th className="px-4 py-3">Appointment</th>
              <th className="px-4 py-3">Done</th>
              <th className="px-4 py-3">Skipped</th>
              <th className="px-4 py-3">Avg wait</th>
              <th className="px-4 py-3">Avg consult</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {data.days.length === 0 && (
              <tr>
                <td colSpan={8} className="px-4 py-6 text-center text-slate-400">No entries in this range.</td>
              </tr>
            )}
            {data.days.map((day) => (
              <tr key={day.date}>
                <td className="px-4 py-3 text-slate-500">{day.date}</td>
                <td className="px-4 py-3">{day.total_entries}</td>
                <td className="px-4 py-3">{day.walk_in}</td>
                <td className="px-4 py-3">{day.appointment}</td>
                <td className="px-4 py-3">{day.done}</td>
                <td className="px-4 py-3">{day.skipped}</td>
                <td className="px-4 py-3">{day.avg_wait_minutes ?? '—'}</td>
                <td className="px-4 py-3">{day.avg_consult_minutes ?? '—'}</td>
              </tr>
            ))}
            <tr className="bg-slate-50 font-semibold text-slate-900">
              <td className="px-4 py-3">Total</td>
              <td className="px-4 py-3">{data.totals.total_entries}</td>
              <td className="px-4 py-3">{data.totals.walk_in}</td>
              <td className="px-4 py-3">{data.totals.appointment}</td>
              <td className="px-4 py-3">{data.totals.done}</td>
              <td className="px-4 py-3">{data.totals.skipped}</td>
              <td className="px-4 py-3">{data.totals.avg_wait_minutes ?? '—'}</td>
              <td className="px-4 py-3">{data.totals.avg_consult_minutes ?? '—'}</td>
            </tr>
          </tbody>
        </table>
      )}
    </section>
  )
}

function VisitSummarySection() {
  const [dateFrom, setDateFrom] = useState(todayIso())
  const [dateTo, setDateTo] = useState(todayIso())
  const [data, setData] = useState<VisitSummaryResponse | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsLoading(true)
    try {
      const response = await api.get<VisitSummaryResponse>('/admin/reports/visit-summary', {
        params: { date_from: dateFrom, date_to: dateTo },
      })
      setData(response.data)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to load the visit summary.'))
    } finally {
      setIsLoading(false)
    }
  }

  function handleCsv() {
    if (!data) return
    const header = ['Date', 'Visits']
    const rows = data.by_day.map((d) => [d.date, d.count])
    downloadCsv(`visit-summary-${data.date_from}-to-${data.date_to}.csv`, [header, ...rows])
  }

  return (
    <section className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm print:mb-8 print:border-0 print:shadow-none">
      <div className="border-b border-slate-200 px-5 py-4">
        <h2 className="text-sm font-semibold text-slate-900">Visit summary</h2>
        <p className="text-xs text-slate-500">Visit records recorded within a date range.</p>
      </div>

      <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-3 border-b border-slate-100 p-5 print:hidden">
        <div>
          <label htmlFor="report-from-2" className="mb-1 block text-xs font-medium text-slate-700">From</label>
          <input id="report-from-2" type="date" required value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="rounded border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label htmlFor="report-to-2" className="mb-1 block text-xs font-medium text-slate-700">To</label>
          <input id="report-to-2" type="date" required value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="rounded border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <button type="submit" disabled={isLoading} className={buttonClass.primary}>
          {isLoading ? 'Running…' : 'Run'}
        </button>
        {data && <ReportActions onCsv={handleCsv} />}
      </form>

      {error && <p className="px-5 py-3 text-sm text-red-700">{error}</p>}

      {data && (
        <div className="grid gap-0 sm:grid-cols-2">
          <div className="border-r border-slate-100 p-5">
            <p className="text-xs font-medium uppercase text-slate-500">Total visits</p>
            <p className="text-2xl font-semibold text-gray-900">{data.total_visits}</p>
            <p className="mt-1 text-xs text-slate-500">{data.referred_count} referred out</p>

            <table className="mt-4 w-full text-left text-sm">
              <thead className="text-xs uppercase text-slate-500">
                <tr>
                  <th className="py-2">Date</th>
                  <th className="py-2">Visits</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.by_day.map((d) => (
                  <tr key={d.date}>
                    <td className="py-2 text-slate-500">{d.date}</td>
                    <td className="py-2">{d.count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="p-5">
            <p className="text-xs font-medium uppercase text-slate-500">Top diagnoses</p>
            <ul className="mt-2 divide-y divide-slate-100">
              {data.top_diagnoses.map((d) => (
                <li key={d.diagnosis} className="flex items-center justify-between py-2 text-sm">
                  <span className="text-slate-700">{d.diagnosis}</span>
                  <span className="font-medium text-slate-900">{d.count}</span>
                </li>
              ))}
              {data.top_diagnoses.length === 0 && <li className="py-2 text-sm text-slate-400">No visits in this range.</li>}
            </ul>
          </div>
        </div>
      )}
    </section>
  )
}

function NoShowSummarySection() {
  const [month, setMonth] = useState(todayIso().slice(0, 7))
  const [data, setData] = useState<NoShowSummaryResponse | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsLoading(true)
    try {
      const response = await api.get<NoShowSummaryResponse>('/admin/reports/no-show-summary', { params: { month } })
      setData(response.data)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to load the no-show report.'))
    } finally {
      setIsLoading(false)
    }
  }

  function handleCsv() {
    if (!data) return
    const header = ['Patient', 'Scheduled at', 'Reason']
    const rows = data.appointments.map((a) => [a.patient.name, a.scheduled_at, a.reason])
    downloadCsv(`no-show-report-${data.month}.csv`, [header, ...rows])
  }

  return (
    <section className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm print:mb-8 print:border-0 print:shadow-none">
      <div className="border-b border-slate-200 px-5 py-4">
        <h2 className="text-sm font-semibold text-slate-900">Patient no-show report</h2>
        <p className="text-xs text-slate-500">Which patients missed booked appointments in a given month.</p>
      </div>

      <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-3 border-b border-slate-100 p-5 print:hidden">
        <div>
          <label htmlFor="report-month" className="mb-1 block text-xs font-medium text-slate-700">Month</label>
          <input id="report-month" type="month" required value={month} onChange={(e) => setMonth(e.target.value)} className="rounded border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <button type="submit" disabled={isLoading} className={buttonClass.primary}>
          {isLoading ? 'Running…' : 'Run'}
        </button>
        {data && <ReportActions onCsv={handleCsv} />}
      </form>

      {error && <p className="px-5 py-3 text-sm text-red-700">{error}</p>}

      {data && (
        <>
          <div className="grid grid-cols-3 gap-4 p-5">
            <div>
              <p className="text-xs font-medium uppercase text-slate-500">No-shows</p>
              <p className="text-2xl font-semibold text-gray-900">{data.total_no_shows}</p>
            </div>
            <div>
              <p className="text-xs font-medium uppercase text-slate-500">Total appointments</p>
              <p className="text-2xl font-semibold text-gray-900">{data.total_appointments}</p>
            </div>
            <div>
              <p className="text-xs font-medium uppercase text-slate-500">No-show rate</p>
              <p className="text-2xl font-semibold text-gray-900">{(data.no_show_rate * 100).toFixed(1)}%</p>
            </div>
          </div>

          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-4 py-3">Patient</th>
                <th className="px-4 py-3">Scheduled at</th>
                <th className="px-4 py-3">Reason</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.appointments.length === 0 && (
                <tr>
                  <td colSpan={3} className="px-4 py-6 text-center text-slate-400">No no-shows this month.</td>
                </tr>
              )}
              {data.appointments.map((a) => (
                <tr key={a.id}>
                  <td className="px-4 py-3 font-medium text-slate-900">{a.patient.name}</td>
                  <td className="px-4 py-3 text-slate-500">{new Date(a.scheduled_at).toLocaleString()}</td>
                  <td className="px-4 py-3 text-slate-600">{a.reason}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </section>
  )
}

function PeakHoursByWeekdaySection() {
  const [data, setData] = useState<PeakHoursByWeekdayResponse | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleRefresh() {
    setError(null)
    setIsLoading(true)
    try {
      const response = await api.get<PeakHoursByWeekdayResponse>('/admin/reports/peak-hours-by-weekday')
      setData(response.data)
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to load peak hours by weekday.'))
    } finally {
      setIsLoading(false)
    }
  }

  const maxAvg = data
    ? Math.max(1, ...data.weekdays.flatMap((w) => w.hours.map((h) => h.avg_arrivals)))
    : 1

  const allHours = data
    ? Array.from(new Set(data.weekdays.flatMap((w) => w.hours.map((h) => h.hour)))).sort((a, b) => a - b)
    : []

  return (
    <section className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm print:mb-8 print:border-0 print:shadow-none">
      <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Peak hours by day of week</h2>
          <p className="text-xs text-slate-500">Average arrivals per hour, across the last 26 weeks.</p>
        </div>
        <button
          type="button"
          onClick={handleRefresh}
          disabled={isLoading}
          className={`${buttonClass.primary} print:hidden`}
        >
          {isLoading ? 'Loading…' : data ? 'Refresh' : 'Load'}
        </button>
      </div>

      {error && <p className="px-5 py-3 text-sm text-red-700">{error}</p>}

      {data && (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th className="px-4 py-3">Day</th>
                {allHours.map((hour) => (
                  <th key={hour} className="px-2 py-3 text-center">{hour}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.weekdays.map((weekday) => (
                <tr key={weekday.day_of_week}>
                  <td className="px-4 py-3 font-medium text-slate-900">{weekday.label}</td>
                  {allHours.map((hour) => {
                    const cell = weekday.hours.find((h) => h.hour === hour)
                    const opacity = cell ? Math.max(cell.avg_arrivals / maxAvg, 0.08) : 0
                    return (
                      <td key={hour} className="p-0 text-center">
                        <div
                          className="mx-auto flex h-8 w-full items-center justify-center text-xs text-slate-700"
                          style={{ backgroundColor: `rgba(42, 120, 214, ${opacity})` }}
                        >
                          {cell ? cell.avg_arrivals : ''}
                        </div>
                      </td>
                    )
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
