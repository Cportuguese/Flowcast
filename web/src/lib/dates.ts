// Date and time formatting shared across the staff pages. These were copied
// per-page before, which is how five of them ended up defaulting their date
// filters to a UTC "today" while one built it from local parts.

/**
 * Today as YYYY-MM-DD in the browser's own timezone — which on a clinic
 * machine is the clinic's, and so matches what the API calls today.
 *
 * Built from local parts rather than sliced off toISOString(), which is UTC
 * and therefore names the wrong day for eight hours of every Philippine day.
 */
export function todayIso() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

/** An ISO timestamp as a local clock time, e.g. "09:05". */
export function formatTime(iso: string | null) {
  return iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'
}

/** A YYYY-MM-DD date as "Sep 26". Parsed at local midnight, not UTC. */
export function formatShortDate(iso: string) {
  return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

/** A bare "HH:MM" or "HH:MM:SS" schedule time as "9:00 AM". */
export function formatClock(time: string) {
  const [h, m] = time.split(':')
  return new Date(2000, 0, 1, Number(h), Number(m)).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}
