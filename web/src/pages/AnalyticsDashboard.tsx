import axios from 'axios'
import { useEffect, useState } from 'react'
import {
  Area,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ComposedChart,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { api } from '../lib/api'
import { PageHeader } from '../components/PageHeader'
import { CHART_COLORS } from '../lib/theme'
import { StatTile } from '../components/StatTile'

const {
  actual: COLOR_ACTUAL,
  forecast: COLOR_FORECAST,
  band: COLOR_BAND,
  muted: COLOR_MUTED,
  grid: COLOR_GRID,
} = CHART_COLORS

/** How many features the importance chart shows before it stops being readable. */
const MAX_IMPORTANCE_BARS = 8

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
    estimated_wait_low: number
    estimated_wait_high: number
  }>
}

interface VolumeForecast {
  series: Array<{
    date: string
    type: 'actual' | 'forecast'
    arrivals?: number
    predicted_arrivals?: number
    low?: number
    high?: number
    is_event_day: boolean
    source?: string
  }>
  model: {
    is_trained: boolean
    version: number | null
    mae: number | null
    baseline_mae: number | null
  }
}

interface PeakHours {
  hours: Array<{ hour: number; arrival_count: number }>
  peak_hour: number | null
}

interface WaitTimeForecast {
  trend: Array<{ date: string; avg_wait_minutes: number }>
}

interface ModelMetrics {
  mae: number | null
  rmse: number | null
  r2: number | null
  baseline_mae: number | null
  baseline_rmse: number | null
  improvement_percent: number | null
  interval_coverage_percent: number | null
}

interface ModelDetail {
  is_trained: boolean
  version: number | null
  trained_at: string | null
  algorithm: string | null
  lambda: number | null
  training_rows: number
  holdout_rows: number
  metrics: ModelMetrics | null
  feature_importances: Array<{ feature: string; coefficient: number; importance: number }>
}

interface ModelPerformance {
  wait_time: ModelDetail
  volume: ModelDetail
  online: {
    window_days: number
    points: Array<{ date: string; queue_number: number; predicted: number; actual: number }>
    metrics: { mae: number | null; n: number }
  }
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

function formatFeatureName(feature: string): string {
  return feature.replace(/_/g, ' ').replace(/\bdow (\d)\b/, (_, day) => `weekday ${day}`)
}

/**
 * Recharts draws a band as a stacked pair: an invisible floor at p10 and a
 * visible span of (p90 - p10) on top of it. Only forecast rows carry a
 * band — history has no uncertainty to show.
 */
function buildVolumeChartData(series: VolumeForecast['series']) {
  const rows = series.map((row) => ({
    date: row.date,
    actual: row.type === 'actual' ? (row.arrivals ?? null) : null,
    forecast: row.type === 'forecast' ? (row.predicted_arrivals ?? null) : null,
    bandFloor: row.type === 'forecast' ? (row.low ?? null) : null,
    bandSpan: row.type === 'forecast' && row.low != null && row.high != null ? row.high - row.low : null,
  }))

  // Join the dashed forecast onto the last real observation so the line is
  // continuous rather than starting in mid-air.
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

function formatTrainedAt(value: string | null): string {
  if (!value) {
    return '—'
  }
  return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/**
 * How the trained model is doing against the heuristic it replaced, and
 * what it says drives clinic waits.
 */
function ModelPerformanceSection({ performance }: { performance: ModelPerformance }) {
  const waitTime = performance.wait_time
  const metrics = waitTime.metrics
  const online = performance.online

  const importances = waitTime.feature_importances
    .slice(0, MAX_IMPORTANCE_BARS)
    .map((row) => ({ ...row, label: formatFeatureName(row.feature) }))
    .reverse()

  if (!waitTime.is_trained) {
    return (
      <section className="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h2 className="text-sm font-semibold text-slate-900">Model performance</h2>
        <p className="mt-2 text-sm text-slate-500">
          No model has been trained yet, so wait estimates come from the people-ahead × average-consult
          heuristic. Run <code className="rounded bg-slate-100 px-1 py-0.5 text-xs">php artisan ml:train</code> once
          the clinic has a few weeks of history.
        </p>
      </section>
    )
  }

  return (
    <section className="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-sm font-semibold text-slate-900">Model performance — wait time</h2>
        <p className="text-xs text-slate-400">
          {waitTime.algorithm?.replace(/_/g, ' ')} v{waitTime.version} · λ {waitTime.lambda}
        </p>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <StatTile
          label="Mean error"
          value={metrics?.mae != null ? `${metrics.mae} min` : '—'}
          hint={metrics?.baseline_mae != null ? `heuristic: ${metrics.baseline_mae} min` : undefined}
        />
        <StatTile
          label="Vs baseline"
          value={metrics?.improvement_percent != null ? `${Math.abs(metrics.improvement_percent)}%` : '—'}
          hint={
            metrics?.improvement_percent == null
              ? undefined
              : metrics.improvement_percent >= 0
                ? 'less error than the heuristic'
                : 'MORE error than the heuristic'
          }
        />
        <StatTile
          label="R²"
          value={metrics?.r2 ?? '—'}
          hint={`${waitTime.holdout_rows.toLocaleString()} held out`}
        />
        <StatTile
          label="Trained on"
          value={waitTime.training_rows.toLocaleString()}
          hint={`arrivals · ${formatTrainedAt(waitTime.trained_at)}`}
        />
      </div>

      <p className="mt-3 text-xs text-slate-500">
        Measured on the most recent {waitTime.holdout_rows.toLocaleString()} arrivals, which were held out of
        training in date order — never sampled at random, because this is a time series.
        {metrics?.interval_coverage_percent != null &&
          ` ${metrics.interval_coverage_percent}% of real waits landed inside the predicted range (80% is the target).`}
      </p>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <div>
          <h3 className="text-xs font-semibold uppercase text-slate-500">What drives the wait</h3>
          <p className="mt-1 text-xs text-slate-400">
            Standardised coefficients, so they are comparable. Bars to the right add waiting time; bars to the
            left cut it.
          </p>
          <div className="mt-3" style={{ height: importances.length * 32 + 24 }}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={importances} layout="vertical" margin={{ top: 0, right: 40, left: 8, bottom: 0 }}>
                <CartesianGrid stroke={COLOR_GRID} horizontal={false} />
                <XAxis type="number" tick={{ fill: COLOR_MUTED, fontSize: 11 }} />
                <YAxis
                  type="category"
                  dataKey="label"
                  width={132}
                  tick={{ fill: COLOR_MUTED, fontSize: 11 }}
                  tickLine={false}
                />
                <Tooltip
                  cursor={{ fill: COLOR_GRID, fillOpacity: 0.3 }}
                  contentStyle={{ fontSize: 12, borderRadius: 8, borderColor: COLOR_GRID }}
                />
                <Bar dataKey="coefficient" name="Coefficient" radius={[0, 4, 4, 0]} barSize={14}>
                  {importances.map((row) => (
                    <Cell key={row.feature} fill={row.coefficient >= 0 ? COLOR_FORECAST : COLOR_ACTUAL} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        </div>

        <div>
          <h3 className="text-xs font-semibold uppercase text-slate-500">Predicted vs actual, live</h3>
          <p className="mt-1 text-xs text-slate-400">
            Estimates as they were shown to patients at check-in, against the wait they actually had — over the
            last {online.window_days} days.
          </p>
          {online.points.length === 0 ? (
            <p className="mt-6 text-sm text-slate-400">
              No live predictions recorded yet. This fills in as patients check in.
            </p>
          ) : (
            <>
              <div className="mt-3 h-56">
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={online.points} margin={{ top: 4, right: 8, left: -16, bottom: 0 }}>
                    <CartesianGrid stroke={COLOR_GRID} vertical={false} />
                    <XAxis
                      dataKey="date"
                      tickFormatter={formatShortDate}
                      tick={{ fill: COLOR_MUTED, fontSize: 11 }}
                      interval="preserveStartEnd"
                    />
                    <YAxis tick={{ fill: COLOR_MUTED, fontSize: 11 }} allowDecimals={false} />
                    <Tooltip
                      labelFormatter={(value) => formatShortDate(String(value))}
                      contentStyle={{ fontSize: 12, borderRadius: 8, borderColor: COLOR_GRID }}
                    />
                    <Legend />
                    <Line type="monotone" dataKey="actual" name="Actual wait" stroke={COLOR_ACTUAL} strokeWidth={2} dot={false} />
                    <Line type="monotone" dataKey="predicted" name="Predicted" stroke={COLOR_FORECAST} strokeWidth={2} strokeDasharray="5 4" dot={false} />
                  </LineChart>
                </ResponsiveContainer>
              </div>
              {online.metrics.mae != null && (
                <p className="mt-2 text-xs text-slate-500">
                  Live mean error: {online.metrics.mae} min across {online.metrics.n.toLocaleString()} arrivals.
                </p>
              )}
            </>
          )}
        </div>
      </div>
    </section>
  )
}

export function AnalyticsDashboard() {
  const [dashboard, setDashboard] = useState<Dashboard | null>(null)
  const [volume, setVolume] = useState<VolumeForecast | null>(null)
  const [peakHours, setPeakHours] = useState<PeakHours | null>(null)
  const [waitTime, setWaitTime] = useState<WaitTimeForecast | null>(null)
  const [performance, setPerformance] = useState<ModelPerformance | null>(null)
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

  // Admin-only, and a personnel account seeing a 403 here is expected rather
  // than an error worth showing — so this loads on its own.
  useEffect(() => {
    api
      .get<ModelPerformance>('/analytics/model-performance')
      .then((res) => setPerformance(res.data))
      .catch(() => setPerformance(null))
  }, [])

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader title="Analytics" />

      {error && <p role="alert" className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>}

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
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold text-slate-900">Patient volume (past 4 weeks + 7-day forecast)</h2>
                {volume?.model.is_trained && (
                  <p className="text-xs text-slate-400">
                    model v{volume.model.version}
                    {volume.model.mae != null && ` · ±${volume.model.mae} arrivals typical error`}
                  </p>
                )}
              </div>
              {volume && (
                <>
                  <div className="mt-4 h-64">
                    <ResponsiveContainer width="100%" height="100%">
                      <ComposedChart data={buildVolumeChartData(volume.series)} margin={{ top: 4, right: 8, left: -16, bottom: 0 }}>
                        <CartesianGrid stroke={COLOR_GRID} vertical={false} />
                        <XAxis
                          dataKey="date"
                          tickFormatter={formatShortDate}
                          tick={{ fill: COLOR_MUTED, fontSize: 12 }}
                          interval="preserveStartEnd"
                        />
                        <YAxis tick={{ fill: COLOR_MUTED, fontSize: 12 }} allowDecimals={false} />
                        <Tooltip
                          labelFormatter={(value) => formatShortDate(String(value))}
                          contentStyle={{ fontSize: 12, borderRadius: 8, borderColor: COLOR_GRID }}
                        />
                        <Legend />
                        <Area
                          type="monotone"
                          dataKey="bandFloor"
                          stackId="band"
                          stroke="none"
                          fill="none"
                          legendType="none"
                          tooltipType="none"
                          isAnimationActive={false}
                        />
                        <Area
                          type="monotone"
                          dataKey="bandSpan"
                          stackId="band"
                          stroke="none"
                          fill={COLOR_BAND}
                          fillOpacity={0.85}
                          legendType="none"
                          isAnimationActive={false}
                        />
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
                      </ComposedChart>
                    </ResponsiveContainer>
                  </div>
                  <p className="mt-2 text-xs text-slate-400">
                    The shaded band is where the day's total is expected to land 8 times out of 10.
                  </p>
                </>
              )}
            </section>

            {performance && <ModelPerformanceSection performance={performance} />}

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
                      <th className="px-4 py-3">Likely range</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {dashboard.upcoming_hours.map((hour) => (
                      <tr key={hour.hour}>
                        <td className="px-4 py-3 font-medium text-slate-900">{hour.label}</td>
                        <td className="px-4 py-3 text-slate-600">{hour.predicted_arrivals}</td>
                        <td className="px-4 py-3 text-slate-600">{hour.estimated_wait_minutes} min</td>
                        <td className="px-4 py-3 text-slate-500">
                          {hour.estimated_wait_low}–{hour.estimated_wait_high} min
                        </td>
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
  )
}
