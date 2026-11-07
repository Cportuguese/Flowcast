// Appointment times are shown on a 12-hour clock ("1:30 PM"). Built by hand
// rather than with toLocaleTimeString, whose output depends on each phone's
// language and region settings: the same appointment could read 13:30 on one
// patient's phone and 1:30 PM on the next.

function twelveHour(hours: number, minutes: number) {
  const period = hours < 12 ? 'AM' : 'PM';
  const hour = hours % 12 === 0 ? 12 : hours % 12;
  return `${hour}:${String(minutes).padStart(2, '0')} ${period}`;
}

/** A timestamp from the API as the phone's local time, e.g. "1:30 PM". */
export function formatTime(iso: string) {
  const date = new Date(iso);
  return twelveHour(date.getHours(), date.getMinutes());
}

/** A booking slot from the API ("13:30") as "1:30 PM". */
export function formatSlotTime(slot: string) {
  const [hours, minutes] = slot.split(':').map(Number);
  return twelveHour(hours, minutes);
}

// Day and month names are spelled out here for the same reason as the
// clock above: toLocaleDateString differs from phone to phone.
export const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

export const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

export const WEEKDAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** A local date as YYYY-MM-DD (never via toISOString, which is UTC). */
export function dateKey(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** "2026-10-14" (or a full timestamp) as "Wednesday, October 14". */
export function formatLongDate(iso: string) {
  const date = iso.length === 10 ? new Date(`${iso}T00:00:00`) : new Date(iso);
  return `${WEEKDAY_NAMES[date.getDay()]}, ${MONTH_NAMES[date.getMonth()]} ${date.getDate()}`;
}

/** A YYYY-MM-DD birthdate as "Mar 4, 2004". */
export function formatBirthdate(iso: string) {
  const [year, month, day] = iso.split('-').map(Number);
  return `${MONTH_NAMES[month - 1].slice(0, 3)} ${day}, ${year}`;
}

/** A timestamp as "Wed, Oct 14". */
export function formatShortDay(iso: string) {
  const date = new Date(iso);
  return `${WEEKDAY_SHORT[date.getDay()]}, ${MONTH_NAMES[date.getMonth()].slice(0, 3)} ${date.getDate()}`;
}
