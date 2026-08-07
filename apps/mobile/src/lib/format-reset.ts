/**
 * "resets at …" for a quota boundary (T-051).
 *
 * The boundary is midnight UTC, but a person reads the clock they are looking
 * at — so it renders in the DEVICE timezone. It includes the DATE when the
 * reset is not today: at 22:00 local the next UTC midnight can be 21:00
 * *tomorrow*, and a bare "resets at 21:00" reads as "in an hour" when it is
 * twenty-three away.
 */
export function formatResetAt(iso: string, now: Date = new Date()): string {
  const at = new Date(iso);

  // A malformed timestamp must not render "Invalid Date" inside user copy.
  if (Number.isNaN(at.getTime())) return '';

  const sameDay = at.toDateString() === now.toDateString();

  return sameDay
    ? at.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
    : at.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
}
