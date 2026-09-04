import { useEffect, useState } from 'react';

/**
 * How old the current payload is, in ms, re-read on a timer.
 *
 * Its own hook so the impure clock read stays inside an effect — `Date.now()` in
 * a render body is impure and the React Compiler rejects it. Reading it on a
 * timer is also the better behaviour: a screen left open past the trust window
 * loses its claim on its own, instead of holding a verdict that quietly went out
 * of date.
 *
 * Shared by every surface that shows an open/closed cue (T-156). It was the
 * place detail's private helper first; the map sheet needs the identical
 * staleness rule, and two copies of a clock are two clocks that disagree.
 *
 * The interval is coarse on purpose: this decides whether a status cue is still
 * trustworthy (see `OPEN_STATE_MAX_AGE_MS`), not anything that needs to tick.
 */
export function useAgeOf(fetchedAt: number): number {
  const [age, setAge] = useState(0);

  useEffect(() => {
    const tick = () => setAge(Date.now() - fetchedAt);
    tick();
    const id = setInterval(tick, 30_000);

    return () => clearInterval(id);
  }, [fetchedAt]);

  return age;
}
