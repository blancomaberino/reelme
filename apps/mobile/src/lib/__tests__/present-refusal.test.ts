import { presentRefusal } from '@/lib/location';

/**
 * The four-way refusal decision (T-158).
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
 *
 * `imprecise` is the fourth, added when review found that bounding the fix by
 * accuracy left iOS users with Precise Location OFF on a permanent "try again"
 * that could not ever succeed. It is the mirror of the `unavailable` bug: a
 * retry pointed at something only Settings can change.
 */
const REASONS = ['blocked', 'denied', 'unavailable', 'imprecise'] as const;

it.each([
  ['blocked', { tone: 'needsPermission', openSettings: true }],
  ['denied', { tone: 'needsPermission', openSettings: false }],
  ['unavailable', { tone: 'noFix', openSettings: false }],
  ['imprecise', { tone: 'imprecise', openSettings: true }],
] as const)('presents %s as its own outcome', (reason, expected) => {
  expect(presentRefusal(reason)).toEqual(expected);
});

it('gives every reason a distinct presentation, so none can be silently collapsed', () => {
  // The table above would still pass if two reasons were merged and the table
  // edited to match. This asserts the SHAPE the function exists to preserve:
  // as many different answers as there are inputs.
  const shapes = REASONS.map((r) => JSON.stringify(presentRefusal(r)));

  expect(new Set(shapes).size).toBe(REASONS.length);
});

it('sends to Settings exactly the reasons a retry cannot fix', () => {
  // `denied` can still be re-requested in-app and `unavailable` is a genuine
  // "try in a moment", so both get a retry; offering Settings there sends
  // someone out of the app for something a tap would fix. `blocked` and
  // `imprecise` are the two an in-app retry can NEVER resolve — the OS will not
  // re-prompt, and Precise Location is not ours to turn on.
  const settingsBound = REASONS.filter((r) => presentRefusal(r).openSettings);

  expect(settingsBound).toEqual(['blocked', 'imprecise']);
});

it('tells a coarse fix apart from no fix, because the advice is opposite', () => {
  // The regression this reason exists to prevent: collapsing `imprecise` into
  // `unavailable` renders "try again in a moment" and a retry button to a user
  // whose next 5 s GPS watch is guaranteed to be refused for the same reason,
  // forever. Both halves are asserted — the copy AND where the button goes.
  expect(presentRefusal('imprecise')).not.toEqual(presentRefusal('unavailable'));
  expect(presentRefusal('imprecise').openSettings).toBe(true);
});
