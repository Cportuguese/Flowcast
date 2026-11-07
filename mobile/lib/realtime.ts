import { useEffect } from 'react';
import { getEcho } from './echo';

/**
 * Runs `onUpdate` whenever one of this patient's bookings changes — the
 * nurse confirmed or declined it, or someone ahead in the same line was
 * seen — so the screen can reload. The event carries ids only; the screen
 * fetches the details over its own authenticated request.
 */
export function useAppointmentUpdates(userId: number | undefined, onUpdate: () => void) {
  useEffect(() => {
    if (!userId) return;

    let active = true;
    let channel: { listen: (event: string, cb: () => void) => unknown; stopListening: (event: string, cb?: () => void) => unknown } | null = null;
    const handler = () => onUpdate();

    getEcho()
      .then((echo) => {
        if (!active) return;
        channel = echo.private(`App.Models.User.${userId}`);
        channel.listen('.appointment.updated', handler);
      })
      .catch(() => {
        // Live updates are a bonus; pull-to-refresh still works.
      });

    return () => {
      active = false;
      channel?.stopListening('.appointment.updated', handler);
    };
  }, [userId, onUpdate]);
}
