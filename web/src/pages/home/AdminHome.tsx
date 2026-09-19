import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { PageHeader } from '../../components/PageHeader'
import { StatTile } from '../../components/StatTile'
import { api } from '../../lib/api'
import { useTodayQueue } from '../../lib/useTodayQueue'

interface DashboardStats {
  is_event_day: boolean
  event_label: string | null
  stats: { avg_wait_minutes_today: number | null }
}

interface VolumePoint {
  date: string
  type: 'actual' | 'forecast'
  arrivals?: number
}

interface NoShowSummary {
  month: string
  total_no_shows: number
  total_appointments: number
}

interface ClinicSchedule {
  day_of_week: number
  start_time: string
  end_time: string
  is_active: boolean
}

interface EventDay {
  id: number
  date: string
  label: string
}

interface AuditEntry {
  id: number
  user: { name: string; role: string } | null
  action: string
  model_type: string
  model_id: number | null
  created_at: string
}

// Same "actual" blue as the Analytics charts.
const COLOR_ACTUAL = '#2a78d6'
const SPARKLINE_DAYS = 7
const ACTIVITY_LIMIT = 5

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function currentMonth() {
  return todayIso().slice(0, 7)
}

function formatShortDate(iso: string) {
  return new Date(`${iso}T00:00:00`).toLocaleDateString([], { month: 'short', day: 'numeric' })
}

function formatTime(iso: string) {
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatClock(time: string) {
  const [h, m] = time.split(':')
  return new Date(2000, 0, 1, Number(h), Number(m)).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

/** "queue.called" -> "called", "event_day.created" -> "event day created". */
function describeAction(log: AuditEntry) {
  const [subject, verb] = log.action.split('.')
  const model = log.model_id ? ` #${log.model_id}` : ''
  if (subject === 'queue' || subject === 'visit' || subject === 'appointment') {
    return `${verb?.replace('_', ' ') ?? subject} ${subject}${model}`
  }
  return `${subject.replace('_', ' ')} ${verb?.replace('_', ' ') ?? ''}`.trim()
}

const cardLink = 'mt-3 inline-block text-sm font-medium text-blue-600 hover:underline'

export function AdminHome() {
  const queue = useTodayQueue()
  const [stats, setStats] = useState<DashboardStats | null>(null)
  const [volume, setVolume] = useState<VolumePoint[]>([])
  const [noShows, setNoShows] = useState<NoShowSummary | null>(null)
  const [schedules, setSchedules] = useState<ClinicSchedule[]>([])
  const [eventDays, setEventDays] = useState<EventDay[]>([])
  const [activity, setActivity] = useState<AuditEntry[]>([])

  useEffect(() => {
    // Each card degrades on its own; one failing request shouldn't blank the page.
    api.get<{ series: VolumePoint[] }>('/analytics/volume-forecast', { params: { days: 1 } })
      .then((r) => setVolume(r.data.series))
      .catch(() => {})
    api.get<NoShowSummary>('/admin/reports/no-show-summary', { params: { month: currentMonth() } })
      .then((r) => setNoShows(r.data))
      .catch(() => {})
    api.get<ClinicSchedule[]>('/admin/schedules').then((r) => setSchedules(r.data)).catch(() => {})
    api.get<EventDay[]>('/admin/event-days').then((r) => setEventDays(r.data)).catch(() => {})
    api.get<AuditEntry[]>('/admin/audit-logs', { params: { date_from: todayIso() } })
      .then((r) => setActivity(r.data.slice(0, ACTIVITY_LIMIT)))
      .catch(() => {})
  }, [])

  useEffect(() => {
    if (queue.isLoading) return
    api.get<DashboardStats>('/analytics/dashboard').then((r) => setStats(r.data)).catch(() => {})
  }, [queue.entries, queue.isLoading])

  const nowServing = queue.inConsultation[0] ?? null
  const avgWait = stats?.stats.avg_wait_minutes_today

  const sparkline = volume
    .filter((p) => p.type === 'actual')
    .slice(-SPARKLINE_DAYS)
    .map((p) => ({ date: p.date, label: formatShortDate(p.date), arrivals: p.arrivals ?? 0 }))

  const todaySchedule = schedules.find((s) => s.day_of_week === new Date().getDay())
  const nextEventDay = eventDays.find((e) => e.date >= todayIso())

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader title="Overview" />

      {stats?.is_event_day && (
        <p className="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Event day{stats.event_label ? `: ${stats.event_label}` : ''} — expect higher volume.
        </p>
      )}

      <div className="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <StatTile label="Waiting" value={queue.waiting.length} />
        <StatTile label="Done today" value={queue.doneCount} />
        <StatTile label="Avg wait" value={avgWait != null ? `${avgWait} min` : '—'} />
        <StatTile
          label="No-shows"
          value={noShows?.total_no_shows ?? '—'}
          hint={noShows ? `of ${noShows.total_appointments} this month` : undefined}
        />
        <StatTile
          label="Event day"
          value={stats?.is_event_day ? (stats.event_label ?? 'Yes') : 'None'}
        />
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-900">Queue now</h2>
          <p className="mt-2 text-sm text-slate-700">
            {queue.isLoading ? (
              'Loading…'
            ) : (
              <>
                {nowServing ? (
                  <>
                    Serving <span className="font-semibold text-gray-900">#{nowServing.queue_number}</span>
                  </>
                ) : (
                  'No one in consultation'
                )}
                {' · '}
                {queue.waiting.length} waiting
              </>
            )}
          </p>
          <Link to="/queue" className={cardLink}>
            Open Live Queue →
          </Link>
        </section>

        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-900">Last {SPARKLINE_DAYS} days</h2>
          {sparkline.length === 0 ? (
            <p className="mt-2 text-sm text-slate-400">No arrival data yet.</p>
          ) : (
            <div className="mt-2 h-20">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={sparkline} margin={{ top: 6, right: 6, bottom: 0, left: 6 }}>
                  <XAxis dataKey="label" hide />
                  <YAxis hide domain={[0, 'dataMax']} />
                  <Tooltip
                    cursor={{ stroke: '#e1e0d9' }}
                    contentStyle={{ fontSize: 12, borderRadius: 8, borderColor: '#e1e0d9' }}
                    formatter={(value) => [`${value} arrivals`, '']}
                    labelFormatter={(label) => String(label)}
                  />
                  <Line
                    type="monotone"
                    dataKey="arrivals"
                    stroke={COLOR_ACTUAL}
                    strokeWidth={2}
                    dot={false}
                    activeDot={{ r: 4 }}
                    isAnimationActive={false}
                  />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}
          <p className="mt-1 text-xs text-slate-500">Arrivals per day</p>
          <Link to="/analytics" className={cardLink}>
            View analytics →
          </Link>
        </section>

        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-900">Clinic</h2>
          <p className="mt-2 text-sm text-slate-700">
            {todaySchedule?.is_active
              ? `Open today ${formatClock(todaySchedule.start_time)}–${formatClock(todaySchedule.end_time)}`
              : 'Closed today'}
          </p>
          <p className="mt-1 text-sm text-slate-500">
            {nextEventDay
              ? `Next event day: ${formatShortDate(nextEventDay.date)} · ${nextEventDay.label}`
              : 'No upcoming event days'}
          </p>
          <Link to="/admin/settings" className={cardLink}>
            Clinic Settings →
          </Link>
        </section>

        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <h2 className="text-sm font-semibold text-slate-900">Recent activity</h2>
          {activity.length === 0 ? (
            <p className="mt-2 text-sm text-slate-400">Nothing logged today yet.</p>
          ) : (
            <ul className="mt-2 space-y-1.5 text-sm">
              {activity.map((log) => (
                <li key={log.id} className="flex gap-3">
                  <span className="shrink-0 text-xs text-slate-400">{formatTime(log.created_at)}</span>
                  <span className="text-slate-700">
                    <span className="font-medium text-slate-900">{log.user?.name ?? 'System'}</span>{' '}
                    {describeAction(log)}
                  </span>
                </li>
              ))}
            </ul>
          )}
          <Link to="/admin/audit-log" className={cardLink}>
            View audit log →
          </Link>
        </section>
      </div>

      <div className="mt-6 flex flex-wrap gap-4 text-sm font-medium">
        <Link to="/admin/reports" className="text-blue-600 hover:underline">
          Reports →
        </Link>
        <Link to="/admin/queue-history" className="text-blue-600 hover:underline">
          Queue history →
        </Link>
      </div>
    </div>
  )
}
