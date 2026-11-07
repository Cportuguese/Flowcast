import { useCallback, useRef } from 'react'

/**
 * For data that reloads as a filter changes (a date, a professional): each
 * request takes a ticket, and only the newest ticket may update the screen.
 * Otherwise a slow reply for the date the nurse clicked past lands after the
 * reply for the date she stopped on, and the table shows the wrong day.
 *
 *   const begin = useLatestRequest()
 *   const isCurrent = begin()          // when the request starts
 *   if (isCurrent()) setRows(data)     // when it finishes
 */
export function useLatestRequest() {
  const latest = useRef(0)

  return useCallback(() => {
    const ticket = ++latest.current
    return () => ticket === latest.current
  }, [])
}
