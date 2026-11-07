import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { extractErrorMessage } from '../lib/errors'
import { useToast } from './Toast'

interface ScheduleRow {
  id: number
  day_of_week: number
  start_time: string
  end_time: string
  slot_minutes: number
  capacity: number
  is_active: boolean
}

interface ProviderWithHours {
  id: number
  display_name: string
  job_title: string | null
  schedule: ScheduleRow[]
}

interface DayForm {
  start_time: string
  end_time: string
  slot_minutes: number
  capacity: number
  is_active: boolean
}

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

function formFor(row?: ScheduleRow): DayForm {
  return {
    start_time: row?.start_time ?? '08:00',
    end_time: row?.end_time ?? '17:00',
    slot_minutes: row?.slot_minutes ?? 20,
    capacity: row?.capacity ?? 1,
    is_active: row?.is_active ?? false,
  }
}

/**
 * When patients can book each of the clinic's professionals: per weekday,
 * the hours, the slot length and how many patients share a slot. One
 * patient per slot keeps a confirmed booking's time the time they are seen.
 */
export function ProviderHoursSection() {
  const toast = useToast()
  const [providers, setProviders] = useState<ProviderWithHours[]>([])
  const [forms, setForms] = useState<Record<string, DayForm>>({})
  const [saving, setSaving] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api
      .get<ProviderWithHours[]>('/admin/providers')
      .then((response) => {
        setProviders(response.data)
        const next: Record<string, DayForm> = {}
        for (const provider of response.data) {
          for (let day = 0; day <= 6; day++) {
            next[`${provider.id}-${day}`] = formFor(provider.schedule.find((row) => row.day_of_week === day))
          }
        }
        setForms(next)
      })
      .catch((err) => setError(extractErrorMessage(err, "Unable to load the professionals' hours.")))
  }, [])

  function update(key: string, patch: Partial<DayForm>) {
    setForms((current) => ({ ...current, [key]: { ...current[key], ...patch } }))
  }

  async function save(provider: ProviderWithHours, day: number) {
    const key = `${provider.id}-${day}`
    setError(null)
    setSaving(key)
    try {
      const response = await api.post<ScheduleRow>(`/admin/providers/${provider.id}/schedules`, { day_of_week: day, ...forms[key] })
      update(key, formFor(response.data))
      toast(`${provider.display_name}: ${DAY_NAMES[day]} saved.`)
    } catch (err) {
      setError(extractErrorMessage(err, `Unable to save ${DAY_NAMES[day]}.`))
    } finally {
      setSaving(null)
    }
  }

  const input = 'rounded border border-slate-300 px-2 py-1 text-sm'

  return (
    <section className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="border-b border-slate-200 px-5 py-4">
        <h2 className="text-sm font-semibold text-slate-900">When patients can book each professional</h2>
        <p className="text-xs text-slate-500">
          Patients see only the days ticked here. Bookings are held for your review before they count.
        </p>
      </div>

      {error && <p role="alert" className="px-5 py-3 text-sm text-red-700">{error}</p>}

      {providers.length === 0 && !error && (
        <p className="px-5 py-4 text-sm text-slate-400">
          No professionals yet. Create them with <code className="rounded bg-slate-100 px-1 text-xs">php artisan make:staff --profession=…</code>
        </p>
      )}

      {providers.map((provider) => (
        <div key={provider.id} className="border-b border-slate-100 last:border-b-0">
          <p className="px-5 pt-4 text-sm font-medium text-slate-900">
            {provider.display_name}
            {provider.job_title && <span className="ml-2 text-xs font-normal text-slate-500">{provider.job_title}</span>}
          </p>
          <div className="overflow-x-auto px-2 pb-3">
            <table className="w-full min-w-[640px] text-left text-sm">
              <thead className="text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-3 py-2">Day</th>
                  <th className="px-3 py-2">Bookable</th>
                  <th className="px-3 py-2">Start</th>
                  <th className="px-3 py-2">End</th>
                  <th className="px-3 py-2">Slot (min)</th>
                  <th className="px-3 py-2">Patients per slot</th>
                  <th className="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {DAY_NAMES.map((name, day) => {
                  const key = `${provider.id}-${day}`
                  const form = forms[key]
                  if (!form) return null
                  return (
                    <tr key={key} className={form.is_active ? undefined : 'text-slate-400'}>
                      <td className="px-3 py-2 font-medium">{name}</td>
                      <td className="px-3 py-2">
                        <input
                          type="checkbox"
                          aria-label={`${provider.display_name} bookable on ${name}`}
                          checked={form.is_active}
                          onChange={(e) => update(key, { is_active: e.target.checked })}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <input type="time" aria-label={`${name} start`} value={form.start_time} onChange={(e) => update(key, { start_time: e.target.value })} className={input} />
                      </td>
                      <td className="px-3 py-2">
                        <input type="time" aria-label={`${name} end`} value={form.end_time} onChange={(e) => update(key, { end_time: e.target.value })} className={input} />
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="number"
                          min={5}
                          max={240}
                          aria-label={`${name} slot minutes`}
                          value={form.slot_minutes}
                          onChange={(e) => update(key, { slot_minutes: Number(e.target.value) })}
                          className={`${input} w-20`}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <input
                          type="number"
                          min={1}
                          max={20}
                          aria-label={`${name} patients per slot`}
                          value={form.capacity}
                          onChange={(e) => update(key, { capacity: Number(e.target.value) })}
                          className={`${input} w-20`}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <button
                          type="button"
                          disabled={saving === key}
                          onClick={() => save(provider, day)}
                          className="rounded bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                        >
                          {saving === key ? 'Saving…' : 'Save'}
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      ))}
    </section>
  )
}
