import { useCallback, useEffect, useState } from 'react'
import { api } from './api'
import type { StaffAppointment } from './appointments'
import { isoDaysFromToday, todayIso } from './dates'

/** How often the head nurse's screens look for new booking requests. */
const POLL_MS = 60_000

/** Matches AppointmentController::BOOKING_HORIZON_DAYS: no request is further out. */
const BOOKING_HORIZON_DAYS = 60

/**
 * Bookings still waiting for the head nurse's review, soonest first. Only
 * future ones count: a request whose time has passed can no longer be
 * confirmed. Polls, because a new request is not urgent to the second and
 * nobody else needs to be told about it.
 */
export function usePendingReviews(enabled = true) {
  const [pending, setPending] = useState<StaffAppointment[]>([])

  const reload = useCallback(async () => {
    if (!enabled) return
    try {
      // Bounded to today through the booking horizon: requests nobody
      // reviewed stay `pending` for good, and the poll must not keep
      // fetching every one of them since the clinic started.
      const response = await api.get<StaffAppointment[]>('/appointments', {
        params: { status: 'pending', from: todayIso(), to: isoDaysFromToday(BOOKING_HORIZON_DAYS) },
      })
      setPending(response.data.filter((appointment) => new Date(appointment.scheduled_at).getTime() > Date.now()))
    } catch {
      // Leave the last known list; the next poll tries again.
    }
  }, [enabled])

  useEffect(() => {
    if (!enabled) return
    void reload()
    const id = setInterval(() => void reload(), POLL_MS)
    return () => clearInterval(id)
  }, [enabled, reload])

  return { pending, reload }
}
