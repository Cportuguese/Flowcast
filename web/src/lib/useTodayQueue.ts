import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from './api'
import { getEcho } from './echo'
import { extractErrorMessage } from './errors'
import type { QueueEntry } from './queue'

/**
 * Today's queue for staff: initial load, live updates over the
 * `queue.{date}.staff` channel, and the three queue actions.
 * Broadcasts push the full entry list, so actions don't refetch.
 */
export function useTodayQueue() {
  const [date, setDate] = useState<string | null>(null)
  const [entries, setEntries] = useState<QueueEntry[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [actionError, setActionError] = useState<string | null>(null)
  const [isCallingNext, setIsCallingNext] = useState(false)
  const [busyEntryId, setBusyEntryId] = useState<number | null>(null)

  const channelDateRef = useRef<string | null>(null)

  // Re-render every 30s so "time in queue" stays current.
  const [, setTick] = useState(0)
  useEffect(() => {
    const interval = setInterval(() => setTick((t) => t + 1), 30_000)
    return () => clearInterval(interval)
  }, [])

  useEffect(() => {
    let cancelled = false

    api
      .get<{ date: string; entries: QueueEntry[] }>('/queue/today')
      .then((response) => {
        if (cancelled) return
        setDate(response.data.date)
        setEntries(response.data.entries)
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    if (!date || channelDateRef.current === date) return

    const echo = getEcho()
    const channelName = `queue.${date}.staff`
    channelDateRef.current = date

    echo.private(channelName).listen('.queue.updated', (payload: { date: string; entries: QueueEntry[] }) => {
      setEntries(payload.entries)
    })

    return () => {
      echo.leave(`private-${channelName}`)
      channelDateRef.current = null
    }
  }, [date])

  const callNext = useCallback(async () => {
    setActionError(null)
    setIsCallingNext(true)
    try {
      await api.post('/admin/queue/call-next')
    } catch (err) {
      setActionError(extractErrorMessage(err, 'Unable to call the next patient.'))
    } finally {
      setIsCallingNext(false)
    }
  }, [])

  /** Resolves true when the entry was completed (caller then opens the visit form). */
  const complete = useCallback(async (entry: QueueEntry) => {
    setActionError(null)
    setBusyEntryId(entry.id)
    try {
      await api.patch(`/admin/queue/${entry.id}/complete`)
      return true
    } catch (err) {
      setActionError(extractErrorMessage(err, 'Unable to mark this entry as done.'))
      return false
    } finally {
      setBusyEntryId(null)
    }
  }, [])

  const skip = useCallback(async (id: number) => {
    setActionError(null)
    setBusyEntryId(id)
    try {
      await api.patch(`/admin/queue/${id}/skip`)
    } catch (err) {
      setActionError(extractErrorMessage(err, 'Unable to skip this entry.'))
    } finally {
      setBusyEntryId(null)
    }
  }, [])

  const waiting = entries.filter((e) => e.status === 'waiting')
  const inConsultation = entries.filter((e) => e.status === 'in_consultation')
  const doneCount = entries.filter((e) => e.status === 'done').length

  return {
    date,
    entries,
    waiting,
    inConsultation,
    doneCount,
    isLoading,
    actionError,
    setActionError,
    isCallingNext,
    busyEntryId,
    callNext,
    complete,
    skip,
  }
}

/** Minutes an entry has been (or was) in the queue, as display text. */
export function timeInQueue(entry: QueueEntry) {
  if (!entry.joined_at) return '—'
  const end = entry.completed_at ? new Date(entry.completed_at) : new Date()
  const minutes = Math.max(0, Math.round((end.getTime() - new Date(entry.joined_at).getTime()) / 60_000))
  return `${minutes} min`
}
