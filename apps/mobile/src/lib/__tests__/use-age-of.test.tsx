import { act, render } from '@testing-library/react-native';

import { AGE_UNKNOWN, useAgeOf } from '@/lib/use-age-of';

/**
 * T-156. This hook decides whether an open/closed cue is still trustworthy, so
 * the two things worth pinning are the value it reports BEFORE it has read the
 * clock, and that it keeps reading it.
 */
function renderAges(fetchedAt: number): number[] {
  const seen: number[] = [];
  function Probe() {
    // Captured during the render body, so `seen[0]` is the value the first
    // paint used — the frame RNTL's `render` would otherwise hide, because it
    // flushes effects before any assertion can run.
    seen.push(useAgeOf(fetchedAt));
    return null;
  }
  render(<Probe />);

  return seen;
}

it('reports the age as UNKNOWN on the first render, before the clock is read', () => {
  // The regression: seeding at 0 made a payload from last night look brand new
  // for one frame, which is one frame of a green "Abierto" on a shut restaurant.
  const ages = renderAges(Date.now() - 11 * 60 * 60 * 1000);

  expect(ages[0]).toBe(AGE_UNKNOWN);
  expect(ages[0]).not.toBe(0);
});

it('replaces it with the real age once the effect runs', () => {
  // The positive control: if the hook only ever returned Infinity, no cue would
  // ever render and the assertion above would pass for the wrong reason.
  const ages = renderAges(Date.now() - 60_000);

  expect(ages.at(-1)).toBeGreaterThanOrEqual(60_000);
  expect(ages.at(-1)).toBeLessThan(70_000);
});

it('keeps re-reading the clock, so a screen left open loses its claim on its own', () => {
  // Without the interval the hook measures the age ONCE and a sheet held open
  // past the trust window keeps asserting a verdict that has gone stale.
  // Deleting the setInterval leaves every other test in this file green.
  jest.useFakeTimers();
  const fetchedAt = Date.now();
  const seen: number[] = [];
  function Probe() {
    seen.push(useAgeOf(fetchedAt));
    return null;
  }
  render(<Probe />);
  const beforeWaiting = seen.at(-1)!;

  act(() => {
    jest.advanceTimersByTime(10 * 60 * 1000);
  });

  expect(seen.at(-1)!).toBeGreaterThan(beforeWaiting + 9 * 60 * 1000);
  jest.useRealTimers();
});
