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
/**
 * The age before the clock has been read: "unknowable", not "brand new".
 *
 * Infinity rather than 0, and this is the whole correctness of the hook. The
 * first render happens BEFORE the effect below runs, so a 0 seed made every
 * consumer treat its payload as fresh for exactly one frame — and the one
 * consumer that matters renders an open/closed cue. Cold start, offline, a
 * 24h-persisted map query: tapping a pin painted last night's green "Abierto"
 * once, then removed it. That is the confidently-wrong claim the age gate exists
 * to prevent, arriving through the gate itself.
 *
 * Infinity fails in the honest direction: no cue until the clock has actually
 * been read, then the truth.
 */
export const AGE_UNKNOWN = Number.POSITIVE_INFINITY;

export function useAgeOf(fetchedAt: number): number {
  const [age, setAge] = useState(AGE_UNKNOWN);

  useEffect(() => {
    const tick = () => setAge(Date.now() - fetchedAt);
    tick();
    const id = setInterval(tick, 30_000);

    return () => clearInterval(id);
  }, [fetchedAt]);

  return age;
}
