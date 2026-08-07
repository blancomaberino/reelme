import { formatResetAt } from '../format-reset';

/**
 * The "resets at …" fragment inside the daily-limit copy (T-051).
 *
 * The quota boundary is midnight UTC, but nobody is looking at a UTC clock —
 * so what matters here is that the string a person reads is unambiguous about
 * WHEN, not that it matches any particular format.
 *
 * Every timestamp below is built from LOCAL components rather than written as a
 * `Z` literal. The behaviour under test is "same local day or not", so a fixed
 * UTC pair silently changes meaning with the host timezone: `21:00Z` is the same
 * local day as `09:00Z` in Montevideo and the next one in Auckland, and the
 * suite would have passed here and failed in CI.
 */

/** A Date at a local wall-clock time, whatever timezone the host is in. */
function localDate(year: number, month: number, day: number, hour: number): Date {
  return new Date(year, month - 1, day, hour, 0, 0, 0);
}

it('gives a bare time when the reset lands today', () => {
  const now = localDate(2026, 8, 7, 9);
  const label = formatResetAt(localDate(2026, 8, 7, 21).toISOString(), now);

  expect(label).not.toBe('');
  // No date, because "today at 21:00" is not something to spell out. Asserted
  // as "shorter than the dated form" rather than by pattern-matching a date —
  // the format is the locale's business, and every separator guess would be
  // wrong somewhere.
  expect(label.length).toBeLessThan(formatResetAt(localDate(2026, 8, 8, 21).toISOString(), now).length);
});

it('includes the date when the reset is not today', () => {
  const now = localDate(2026, 8, 7, 22);
  const label = formatResetAt(localDate(2026, 8, 8, 21).toISOString(), now);

  // At 22:00 local the next quota reset can be 21:00 TOMORROW, and a bare
  // "resets at 21:00" reads as "in an hour" when it is twenty-three away.
  expect(label).toContain('8');
  expect(label.length).toBeGreaterThan(formatResetAt(localDate(2026, 8, 7, 21).toISOString(), now).length);
});

it('renders nothing rather than "Invalid Date" on a malformed timestamp', () => {
  // This string is interpolated straight into "Daily limit reached — resets at
  // {{time}}." A bad `resets_at` must degrade to an awkward sentence, never to
  // the literal words "Invalid Date" shown to a user.
  expect(formatResetAt('not-a-timestamp')).toBe('');
  expect(formatResetAt('')).toBe('');
});
