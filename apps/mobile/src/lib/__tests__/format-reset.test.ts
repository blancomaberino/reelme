import { formatResetAt } from '../format-reset';

/**
 * The "resets at …" fragment inside the daily-limit copy (T-051).
 *
 * The quota boundary is midnight UTC, but nobody is looking at a UTC clock —
 * so what matters here is that the string a person reads is unambiguous about
 * WHEN, not that it matches any particular format.
 */
it('gives a bare time when the reset lands today', () => {
  const now = new Date('2026-08-07T09:00:00Z');
  const label = formatResetAt('2026-08-07T21:00:00Z', now);

  expect(label).not.toBe('');
  // No date, because "today at 21:00" is not something to spell out.
  expect(label).not.toMatch(/2026|8\/7|07\/08/);
});

it('includes the date when the reset is not today', () => {
  const now = new Date('2026-08-07T09:00:00Z');
  const label = formatResetAt('2026-08-08T21:00:00Z', now);

  // At 22:00 local the next UTC midnight can be 21:00 TOMORROW, and a bare
  // "resets at 21:00" reads as "in an hour" when it is twenty-three away.
  expect(label).toMatch(/8|08/);
  expect(label.length).toBeGreaterThan(formatResetAt('2026-08-07T21:00:00Z', now).length);
});

it('renders nothing rather than "Invalid Date" on a malformed timestamp', () => {
  // This string is interpolated straight into "Daily limit reached — resets at
  // {{time}}." A bad `resets_at` must degrade to an awkward sentence, never to
  // the literal words "Invalid Date" shown to a user.
  expect(formatResetAt('not-a-timestamp')).toBe('');
  expect(formatResetAt('')).toBe('');
});
