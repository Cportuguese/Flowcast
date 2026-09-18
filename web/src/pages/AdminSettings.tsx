import axios from 'axios'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { api } from '../lib/api'

interface ClinicSchedule {
  id: number
  day_of_week: number
  start_time: string
  end_time: string
  slot_minutes: number
  capacity: number
  is_active: boolean
}

interface EventDay {
  id: number
  date: string
  label: string
  note: string | null
}

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

interface DayForm {
  start_time: string
  end_time: string
  slot_minutes: number
  capacity: number
  is_active: boolean
}

function defaultDayForm(schedule?: ClinicSchedule): DayForm {
  return {
    start_time: schedule?.start_time.slice(0, 5) ?? '08:00',
    end_time: schedule?.end_time.slice(0, 5) ?? '17:00',
    slot_minutes: schedule?.slot_minutes ?? 15,
    capacity: schedule?.capacity ?? 1,
    is_active: schedule?.is_active ?? false,
  }
}

function extractErrorMessage(err: unknown, fallback: string) {
  if (axios.isAxiosError(err) && err.response) {
    const data = err.response.data as { message?: string }
    return data.message ?? fallback
  }
  return fallback
}

export function AdminSettings() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const [forms, setForms] = useState<Record<number, DayForm>>({})
  const [isLoading, setIsLoading] = useState(true)
  const [savingDay, setSavingDay] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)

  const [eventDays, setEventDays] = useState<EventDay[]>([])
  const [newDate, setNewDate] = useState('')
  const [newLabel, setNewLabel] = useState('')
  const [newNote, setNewNote] = useState('')
  const [isAddingEventDay, setIsAddingEventDay] = useState(false)
  const [deletingEventDayId, setDeletingEventDayId] = useState<number | null>(null)

  useEffect(() => {
    Promise.all([
      api.get<ClinicSchedule[]>('/admin/schedules'),
      api.get<EventDay[]>('/admin/event-days'),
    ])
      .then(([schedulesRes, eventDaysRes]) => {
        setEventDays(eventDaysRes.data)
        const nextForms: Record<number, DayForm> = {}
        for (let day = 0; day <= 6; day++) {
          nextForms[day] = defaultDayForm(schedulesRes.data.find((s) => s.day_of_week === day))
        }
        setForms(nextForms)
      })
      .catch((err) => setError(extractErrorMessage(err, 'Unable to load clinic settings.')))
      .finally(() => setIsLoading(false))
  }, [])

  function updateForm(day: number, patch: Partial<DayForm>) {
    setForms((prev) => ({ ...prev, [day]: { ...prev[day], ...patch } }))
  }

  async function handleSaveDay(day: number) {
    setError(null)
    setSavingDay(day)
    const form = forms[day]

    try {
      const response = await api.post<ClinicSchedule>('/admin/schedules', {
        day_of_week: day,
        start_time: form.start_time,
        end_time: form.end_time,
        slot_minutes: form.slot_minutes,
        capacity: form.capacity,
        is_active: form.is_active,
      })
      updateForm(day, defaultDayForm(response.data))
    } catch (err) {
      setError(extractErrorMessage(err, `Unable to save ${DAY_NAMES[day]}'s schedule.`))
    } finally {
      setSavingDay(null)
    }
  }

  async function handleAddEventDay(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setIsAddingEventDay(true)

    try {
      const response = await api.post<EventDay>('/admin/event-days', {
        date: newDate,
        label: newLabel,
        note: newNote || null,
      })
      setEventDays((prev) => [...prev, response.data].sort((a, b) => a.date.localeCompare(b.date)))
      setNewDate('')
      setNewLabel('')
      setNewNote('')
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to flag this event day.'))
    } finally {
      setIsAddingEventDay(false)
    }
  }

  async function handleDeleteEventDay(id: number) {
    setError(null)
    setDeletingEventDayId(id)

    try {
      await api.delete(`/admin/event-days/${id}`)
      setEventDays((prev) => prev.filter((e) => e.id !== id))
    } catch (err) {
      setError(extractErrorMessage(err, 'Unable to remove this event day.'))
    } finally {
      setDeletingEventDayId(null)
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
          <div>
            <h1 className="text-2xl font-semibold text-gray-900">Clinic Settings</h1>
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

        {error && (
          <p className="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
        )}

        {isLoading ? (
          <p className="mt-6 text-slate-400">Loading…</p>
        ) : (
          <>
            <section className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <div className="border-b border-slate-200 px-5 py-4">
                <h2 className="text-sm font-semibold text-slate-900">Weekly hours</h2>
                <p className="text-xs text-slate-500">Turn a day on and set its hours, slot length, and capacity per slot.</p>
              </div>
              <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Day</th>
                    <th className="px-4 py-3">Open</th>
                    <th className="px-4 py-3">Start</th>
                    <th className="px-4 py-3">End</th>
                    <th className="px-4 py-3">Slot (min)</th>
                    <th className="px-4 py-3">Capacity</th>
                    <th className="px-4 py-3"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {DAY_NAMES.map((name, day) => {
                    const form = forms[day]
                    if (!form) return null
                    return (
                      <tr key={day}>
                        <td className="px-4 py-3 font-medium text-slate-900">{name}</td>
                        <td className="px-4 py-3">
                          <input
                            type="checkbox"
                            checked={form.is_active}
                            onChange={(e) => updateForm(day, { is_active: e.target.checked })}
                          />
                        </td>
                        <td className="px-4 py-3">
                          <input
                            type="time"
                            value={form.start_time}
                            onChange={(e) => updateForm(day, { start_time: e.target.value })}
                            className="rounded border border-slate-300 px-2 py-1 text-sm"
                          />
                        </td>
                        <td className="px-4 py-3">
                          <input
                            type="time"
                            value={form.end_time}
                            onChange={(e) => updateForm(day, { end_time: e.target.value })}
                            className="rounded border border-slate-300 px-2 py-1 text-sm"
                          />
                        </td>
                        <td className="px-4 py-3">
                          <input
                            type="number"
                            min={5}
                            max={240}
                            value={form.slot_minutes}
                            onChange={(e) => updateForm(day, { slot_minutes: Number(e.target.value) })}
                            className="w-20 rounded border border-slate-300 px-2 py-1 text-sm"
                          />
                        </td>
                        <td className="px-4 py-3">
                          <input
                            type="number"
                            min={1}
                            value={form.capacity}
                            onChange={(e) => updateForm(day, { capacity: Number(e.target.value) })}
                            className="w-20 rounded border border-slate-300 px-2 py-1 text-sm"
                          />
                        </td>
                        <td className="px-4 py-3">
                          <button
                            type="button"
                            disabled={savingDay === day}
                            onClick={() => handleSaveDay(day)}
                            className="rounded bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                          >
                            {savingDay === day ? 'Saving…' : 'Save'}
                          </button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </section>

            <section className="mt-6 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <div className="border-b border-slate-200 px-5 py-4">
                <h2 className="text-sm font-semibold text-slate-900">Event days</h2>
                <p className="text-xs text-slate-500">
                  Flag high-volume or closed dates (enrollment, PATHFIT, holidays). Flagged dates block booking.
                </p>
              </div>

              <form onSubmit={handleAddEventDay} className="grid gap-3 border-b border-slate-100 p-5 sm:grid-cols-4">
                <input
                  type="date"
                  required
                  value={newDate}
                  onChange={(e) => setNewDate(e.target.value)}
                  className="rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                  type="text"
                  required
                  placeholder="Label (e.g. Enrollment week)"
                  value={newLabel}
                  onChange={(e) => setNewLabel(e.target.value)}
                  className="rounded border border-slate-300 px-3 py-2 text-sm sm:col-span-2"
                />
                <input
                  type="text"
                  placeholder="Note (optional)"
                  value={newNote}
                  onChange={(e) => setNewNote(e.target.value)}
                  className="rounded border border-slate-300 px-3 py-2 text-sm"
                />
                <button
                  type="submit"
                  disabled={isAddingEventDay}
                  className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50 sm:col-span-4"
                >
                  {isAddingEventDay ? 'Adding…' : 'Flag this date'}
                </button>
              </form>

              <ul className="divide-y divide-slate-100">
                {eventDays.length === 0 && (
                  <li className="px-5 py-4 text-sm text-slate-400">No event days flagged.</li>
                )}
                {eventDays.map((eventDay) => (
                  <li key={eventDay.id} className="flex items-center justify-between px-5 py-3">
                    <div>
                      <div className="text-sm font-medium text-slate-900">
                        {eventDay.date} — {eventDay.label}
                      </div>
                      {eventDay.note && <div className="text-xs text-slate-500">{eventDay.note}</div>}
                    </div>
                    <button
                      type="button"
                      disabled={deletingEventDayId === eventDay.id}
                      onClick={() => handleDeleteEventDay(eventDay.id)}
                      className="rounded bg-red-100 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-200 disabled:opacity-50"
                    >
                      Remove
                    </button>
                  </li>
                ))}
              </ul>
            </section>
          </>
        )}
      </div>
    </div>
  )
}
