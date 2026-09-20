import { useEffect, useRef, useState } from 'react'
import { api } from '../lib/api'
import { getEcho } from '../lib/echo'

interface BoardEntry {
  queue_number: number
  status: 'waiting' | 'in_consultation' | 'done' | 'skipped'
}

interface BoardHours {
  is_open: boolean
  today: { start_time: string; end_time: string } | null
  next_opening: { date: string; day: string; start_time: string } | null
}

type Connection = 'connecting' | 'live' | 'offline'

const UP_NEXT_LIMIT = 10

function playChime() {
  try {
    const AudioContextClass = window.AudioContext || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext
    const ctx = new AudioContextClass()
    const oscillator = ctx.createOscillator()
    const gain = ctx.createGain()
    oscillator.type = 'sine'
    oscillator.frequency.value = 880
    gain.gain.setValueAtTime(0.2, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 1.2)
    oscillator.connect(gain)
    gain.connect(ctx.destination)
    oscillator.start()
    oscillator.stop(ctx.currentTime + 1.2)
  } catch {
    // Audio isn't critical to the board — ignore if it fails (e.g. no user gesture yet).
  }
}

function useClock() {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1_000)
    return () => clearInterval(id)
  }, [])
  return now
}

function formatClock(time: string) {
  const [h, m] = time.split(':')
  return new Date(2000, 0, 1, Number(h), Number(m)).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

function nextOpeningLabel(next: BoardHours['next_opening']) {
  if (!next) return 'Check back for opening hours'
  const isToday = next.date === new Date().toISOString().slice(0, 10)
  return `Opens ${isToday ? 'today' : next.day} at ${formatClock(next.start_time)}`
}

const CONNECTION_STYLES: Record<Connection, { dot: string; label: string }> = {
  connecting: { dot: 'bg-slate-500', label: 'Connecting' },
  live: { dot: 'bg-green-400', label: 'Live' },
  offline: { dot: 'bg-red-400', label: 'Offline' },
}

export function TvBoard() {
  const [date, setDate] = useState<string | null>(null)
  const [entries, setEntries] = useState<BoardEntry[]>([])
  const [nowServing, setNowServing] = useState<number | null>(null)
  const [hours, setHours] = useState<BoardHours | null>(null)
  const [connection, setConnection] = useState<Connection>('connecting')
  const channelDateRef = useRef<string | null>(null)
  const now = useClock()

  useEffect(() => {
    api.get<{ date: string; entries: BoardEntry[]; hours: BoardHours }>('/queue-board/today').then((response) => {
      setDate(response.data.date)
      setEntries(response.data.entries)
      setHours(response.data.hours)
      const inConsultation = response.data.entries.filter((e) => e.status === 'in_consultation')
      if (inConsultation.length > 0) {
        setNowServing(inConsultation[inConsultation.length - 1].queue_number)
      }
    })
  }, [])

  // Re-check opening hours every few minutes so the board flips to "closed"
  // on its own after the last patient without anyone touching it.
  useEffect(() => {
    const id = setInterval(() => {
      api.get<{ hours: BoardHours }>('/queue-board/today').then((r) => setHours(r.data.hours)).catch(() => {})
    }, 5 * 60_000)
    return () => clearInterval(id)
  }, [])

  useEffect(() => {
    if (!date || channelDateRef.current === date) return
    channelDateRef.current = date

    const echo = getEcho()
    const channel = echo.channel(`queue-board.${date}`)

    channel.listen('.queue.board.updated', (payload: { entries: BoardEntry[] }) => {
      setEntries(payload.entries)
    })

    channel.listen('.ticket.called', (payload: { queue_number: number }) => {
      setNowServing(payload.queue_number)
      playChime()
    })

    // Reverb rides on the Pusher protocol; its connection state is the
    // only signal the board has that it is (or isn't) receiving updates.
    const pusherConnection = echo.connector.pusher.connection
    const onStateChange = ({ current }: { current: string }) => {
      setConnection(current === 'connected' ? 'live' : current === 'connecting' ? 'connecting' : 'offline')
    }
    onStateChange({ current: pusherConnection.state })
    pusherConnection.bind('state_change', onStateChange)

    return () => {
      pusherConnection.unbind('state_change', onStateChange)
      echo.leaveChannel(`queue-board.${date}`)
    }
  }, [date])

  const upcoming = entries.filter((e) => e.status === 'waiting').slice(0, UP_NEXT_LIMIT)
  const isClosed = hours !== null && !hours.is_open && entries.length === 0
  const status = CONNECTION_STYLES[connection]

  return (
    <div className="flex min-h-screen flex-col bg-slate-950 px-8 py-8 text-white">
      <header className="flex items-center justify-between">
        <p className="text-lg uppercase tracking-[0.3em] text-slate-400">UDD Clinic</p>
        <div className="flex items-center gap-6 text-slate-400">
          <p className="text-2xl tabular-nums">
            {now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}
          </p>
          <p className="flex items-center gap-2 text-sm uppercase tracking-widest">
            <span className={`inline-block h-2.5 w-2.5 rounded-full ${status.dot}`} aria-hidden="true" />
            {status.label}
          </p>
        </div>
      </header>

      {isClosed ? (
        <div className="flex flex-1 flex-col items-center justify-center">
          <p className="text-2xl uppercase tracking-widest text-slate-400">Clinic closed</p>
          <p className="mt-6 text-5xl font-semibold">{nextOpeningLabel(hours.next_opening)}</p>
          {hours.today && (
            <p className="mt-4 text-lg text-slate-500">
              Today&rsquo;s hours: {formatClock(hours.today.start_time)} – {formatClock(hours.today.end_time)}
            </p>
          )}
        </div>
      ) : (
        <div className="flex flex-1 flex-col items-center">
          <div className="mt-10 flex flex-col items-center">
            <p className="text-2xl uppercase tracking-widest text-slate-400">Now Serving</p>
            <p className="mt-4 text-[12rem] font-bold leading-none text-blue-400">
              {nowServing !== null ? `#${nowServing}` : '—'}
            </p>
          </div>

          <div className="mt-16 w-full max-w-3xl">
            <p className="text-center text-lg uppercase tracking-widest text-slate-400">Up Next</p>
            <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-5">
              {upcoming.length === 0 && (
                <p className="col-span-full text-center text-slate-500">No one else waiting</p>
              )}
              {upcoming.map((entry) => (
                <div
                  key={entry.queue_number}
                  className="rounded-2xl border border-slate-800 bg-slate-900 py-6 text-center text-4xl font-semibold"
                >
                  {entry.queue_number}
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
