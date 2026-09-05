import { presentRefusal } from '@/lib/location';

/**
 * The three-way refusal decision (T-158).
 *
 * It lives in `lib/location` because two screens need it — Tonight and the
 * offers browse — and it is tested here rather than only through them because
 * the offers browse has no screen test at all: it mounts a MapView. So this is
 * what stands behind that screen's behaviour, and the Tonight screen test
 * covers the same decision end to end through the UI.
 *
 * The distinction is not cosmetic. `unavailable` means permission was GRANTED
 * and the fix timed out — indoors, in a tunnel, a simulator with no location
 * set. Sending that person to Settings points them at a switch already on,
 * which is the bug this function was extracted to stop repeating.
 */
const REASONS = ['blocked', 'denied', 'unavailable'] as const;

it.each([
  ['blocked', { unavailable: false, openSettings: true }],
  ['denied', { unavailable: false, openSettings: false }],
  ['unavailable', { unavailable: true, openSettings: false }],
] as const)('presents %s as its own outcome', (reason, expected) => {
  expect(presentRefusal(reason)).toEqual(expected);
});

it('gives every reason a distinct presentation, so none can be silently collapsed', () => {
  // The table above would still pass if two reasons were merged and the table
  // edited to match. This asserts the SHAPE the function exists to preserve:
  // three inputs, three different answers.
  const shapes = REASONS.map((r) => JSON.stringify(presentRefusal(r)));

  expect(new Set(shapes).size).toBe(REASONS.length);
});

it('only a permanently blocked permission is sent to Settings', () => {
  // `denied` can still be re-requested in-app, so it gets a retry; offering
  // Settings there sends someone out of the app for something a tap would fix.
  const settingsBound = REASONS.filter((r) => presentRefusal(r).openSettings);

  expect(settingsBound).toEqual(['blocked']);
});
