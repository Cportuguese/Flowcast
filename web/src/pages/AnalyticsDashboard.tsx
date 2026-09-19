import axios from 'axios'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { useAuth } from '../context/AuthContext'
import { api } from '../lib/api'

const COLOR_ACTUAL = '#2a78d6'
const COLOR_FORECAST = '#eb6834'
const COLOR_MUTED = '#898781'
const COLOR_GRID = '#e1e0d9'

interface Dashboard {
  date: string
  is_event_day: boolean
  event_label: string | null
  stats: {
    arrivals_today: number
    avg_wait_minutes_today: number | null
    avg_consult_minutes_today: number | null
    currently_waiting: number
    completed_today: number
  }
  upcoming_hours: Array<{
    hour: number
    label: string
    predicted_arrivals: number
    estimated_wait_minutes: number
  }>
}

interface VolumeForecast {
  series: Array<{
    date: string
    type: 'actual' | 'forecast'
    arrivals?: number
    predicted_arrivals?: number
    is_event_day: boolean
  }>
}

interface PeakHours {
  hours: Array<{ hour: number; arrival_count: number }>
  peak_hour: number | null
}

interface WaitTimeForecast {
  trend: Array<{ date: string; avg_wait_minutes: number }>
}

function formatHour(hour: number): string {
  const period = hour < 12 ? 'AM' : 'PM'
  const displayHour = hour % 12 === 0 ? 12 : hour % 12
  return `${displayHour} ${period}`
}

function formatShortDate(dateStr: string): string {
  const date = new Date(`${dateStr}T00:00:00`)
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function buildVolumeChartData(series: VolumeForecast['series']) {
  const rows = series.map((row) => ({
    date: row.date,
    actual: row.type === 'actual' ? (row.arrivals ?? null) : null,
    forecast: row.type === 'forecast' ? (row.predicted_arrivals ?? null) : null,
  }))

  for (let i = rows.length - 1; i >= 0; i--) {
    if (rows[i].actual !== null) {
      rows[i] = { ...rows[i], forecast: rows[i].actual }
      break
    }
  }

  return rows
}

function extractErrorMessage(err: unknown, fallback: string) {
  if (axios.isAxiosError(err) && err.response) {
    const data = err.response.data as { message?: string }
    return data.message ?? fallback
  }
  return fallback
}

export function AnalyticsDashboard() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const [dashboard, setDashboard] = useState<Dashboard | null>(null)
  const [volume, setVolume] = useState<VolumeForecast | null>(null)
  const [peakHours, setPeakHours] = useState<PeakHours | null>(null)
  const [waitTime, setWaitTime] = useState<WaitTimeForecast | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    Promise.all([
      api.get<Dashboard>('/analytics/dashboard'),
      api.get<VolumeForecast>('/analytics/volume-forecast', { params: { days: 7 } }),
      api.get<PeakHours>('/analytics/peak-hours'),
      api.get<WaitTimeForecast>('/analytics/wait-time-forecast'),
    ])
      .then(([dashboardRes, volumeRes, peakHoursRes, waitTimeRes]) => {
        setDashboard(dashboardRes.data)
        setVolume(volumeRes.data)
        setPeakHours(peakHoursRes.data)
        setWaitTime(waitTimeRes.data)
      })
      .catch((err) => setError(extractErrorMessage(err, 'Unable to load analytics.')))
      .finally(() => setIsLoading(false))
  }, [])

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  return (
    <div className="min-h-screen bg-gray-50 p-8">
      <div className="mx-auto max-w-5xl">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-semibold text-gray-900">Analytics</h1>
            <p className="text-sm text-slate-500">Signed in as {user?.name} ({user?.role})</p>
          </div>
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

        {error && <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

        {isLoading ? (
          <p className="mt-6 text-slate-400">Loading…</p>
        ) : (
          dashboard && (
            <>
              {dashboard.is_event_day && (
                <div className="mt-6 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                  Today is flagged as an event day — {dashboard.event_label}. Expect higher-than-usual volume.
                </div>
              )}

              <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
                <StatTile label="Arrivals today" value={dashboard.stats.arrivals_today} />
                <StatTile label="Avg wait (min)" value={dashboard.stats.avg_wait_minutes_today} />
                <StatTile label="Avg consult (min)" value={dashboard.stats.avg_consult_minutes_today} />
                <StatTile label="Currently waiting" value={dashboard.stats.currently_waiting} />
                <StatTile label="Completed today" value={dashboard.stats.completed_today} />
              </div>

              <section className="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 className="text-sm font-semibold text-slate-900">Patient volume (past 4 weeks + 7-day forecast)</h2>
                {volume && (
                  <div className="mt-4 h-64">
                    <ResponsiveContainer width="100%" height="100%">
                      <LineChart data={buildVolumeChartData(volume.series)} margin={{ top: 4, right: 8, left: -16, bottom: 0 }}>
                        <CartesianGrid stroke={COLOR_GRID} vertical={false} />
                        <XAxis
                          dataKey="date"
                          tickFormatter={formatShortDate}
                          tick={{ fill: COLOR_MUTED, fontSize: 12 }}
                          interval="preserveStartEnd"
                        />
                        <YAxis tick={{ fill: COLOR_MUTED, fontSize: 12 }} allowDecimals={false} />
                        <Tooltip labelFormatter={(value) => formatShortDate(String(value))} />
                        <Legend />
                        <Line
                          type="monotone"
                          dataKey="actual"
                          name="Actual"
                          stroke={COLOR_ACTUAL}
                          strokeWidth={2}
                          dot={false}
                          connectNulls={false}
                        />
                        <Line
                          type="monotone"
                          dataKey="forecast"
                          name="Forecast"
                          stroke={COLOR_FORECAST}
                          strokeWidth={2}
                          strokeDasharray="5 4"
                          dot={false}
                          connectNulls={false}
                        />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                )}
              </section>

              <div className="mt-6 grid gap-6 sm:grid-cols-2">
                <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                  <h2 className="text-sm font-semibold text-slate-900">
                    Peak hours {peakHours?.hours.length ? '' : '— no data yet'}
                  </h2>
                  {peakHours && peakHours.hours.length > 0 && (
                    <>
                      <div className="mt-4 h-56">
                        <ResponsiveContainer width="100%" height="100%">
                          <BarChart data={peakHours.hours} margin={{ top: 4, right: 8, left: -16, bottom: 0 }}>
                            <CartesianGrid stroke={COLOR_GRID} vertical={false} />
                            <XAxis dataKey="hour" tickFormatter={formatHour} tick={{ fill: COLOR_MUTED, fontSize: 12 }} />
                            <YAxis tick={{ fill: COLOR_MUTED, fontSize: 12 }} allowDecimals={false} />
                            <Tooltip labelFormatter={(value) => formatHour(Number(value))} />
                            <Bar dataKey="arrival_count" name="Arrivals" radius={[4, 4, 0, 0]}>
                              {peakHours.hours.map((entry) => (
                                <Cell
                                  key={entry.hour}
                                  fill={entry.hour === peakHours.peak_hour ? COLOR_FORECAST : COLOR_ACTUAL}
                                />
                              ))}
                            </Bar>
                          </BarChart>
                        </ResponsiveContainer>
                      </div>
                      {peakHours.peak_hour !== null && (
                        <p className="mt-2 text-xs text-slate-500">Busiest hour: {formatHour(peakHours.peak_hour)}</p>
                      )}
                    </>
                  )}
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                  <h2 className="text-sm font-semibold text-slate-900">Average wait time trend</h2>
                  {waitTime && waitTime.trend.length > 0 ? (
                    <div className="mt-4 h-56">
                      <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={waitTime.trend} margin={{ top: 4, right: 8, left: -16, bottom: 0 }}>
                          <CartesianGrid stroke={COLOR_GRID} vertical={false} />
                          <XAxis
                            dataKey="date"
                            tickFormatter={formatShortDate}
                            tick={{ fill: COLOR_MUTED, fontSize: 12 }}
                            interval="preserveStartEnd"
                          />
                          <YAxis tick={{ fill: COLOR_MUTED, fontSize: 12 }} allowDecimals={false} />
                          <Tooltip labelFormatter={(value) => formatShortDate(String(value))} />
                          <Line
                            type="monotone"
                            dataKey="avg_wait_minutes"
                            name="Avg wait (min)"
                            stroke={COLOR_ACTUAL}
                            strokeWidth={2}
                            dot={false}
                          />
                        </LineChart>
                      </ResponsiveContainer>
                    </div>
                  ) : (
                    <p className="mt-4 text-sm text-slate-400">No data yet.</p>
                  )}
                </section>
              </div>

              <section className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-200 px-5 py-4">
                  <h2 className="text-sm font-semibold text-slate-900">Upcoming hours today</h2>
                </div>
                {dashboard.upcoming_hours.length === 0 ? (
                  <p className="px-5 py-4 text-sm text-slate-400">The clinic is closed for the rest of today.</p>
                ) : (
                  <table className="w-full text-left text-sm">
                    <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                      <tr>
                        <th className="px-4 py-3">Hour</th>
                        <th className="px-4 py-3">Predicted arrivals</th>
                        <th className="px-4 py-3">Estimated wait</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {dashboard.upcoming_hours.map((hour) => (
                        <tr key={hour.hour}>
                          <td className="px-4 py-3 font-medium text-slate-900">{hour.label}</td>
                          <td className="px-4 py-3 text-slate-600">{hour.predicted_arrivals}</td>
                          <td className="px-4 py-3 text-slate-600">{hour.estimated_wait_minutes} min</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </section>
            </>
          )
        )}
      </div>
    </div>
  )
}

function StatTile({ label, value }: { label: string; value: number | null }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
      <p className="text-xs font-medium uppercase text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold text-gray-900">{value ?? '—'}</p>
    </div>
  )
}
