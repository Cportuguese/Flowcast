import { useEffect, useRef, useState } from 'react'
import { api } from '../lib/api'
import { getEcho } from '../lib/echo'

interface BoardEntry {
  queue_number: number
  status: 'waiting' | 'in_consultation' | 'done' | 'skipped'
}

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

export function TvBoard() {
  const [date, setDate] = useState<string | null>(null)
  const [entries, setEntries] = useState<BoardEntry[]>([])
  const [nowServing, setNowServing] = useState<number | null>(null)
  const channelDateRef = useRef<string | null>(null)

  useEffect(() => {
    api.get<{ date: string; entries: BoardEntry[] }>('/queue-board/today').then((response) => {
      setDate(response.data.date)
      setEntries(response.data.entries)
      const inConsultation = response.data.entries.filter((e) => e.status === 'in_consultation')
      if (inConsultation.length > 0) {
        setNowServing(inConsultation[inConsultation.length - 1].queue_number)
      }
    })
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

    return () => {
      echo.leaveChannel(`queue-board.${date}`)
    }
  }, [date])

  const upcoming = entries.filter((e) => e.status === 'waiting').slice(0, 10)

  return (
    <div className="flex min-h-screen flex-col items-center bg-slate-950 px-8 py-12 text-white">
      <p className="text-lg uppercase tracking-[0.3em] text-slate-400">Flowcast Clinic</p>

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
  )
}
