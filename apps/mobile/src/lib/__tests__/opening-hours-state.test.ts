import type { OpenState } from '@/api/places';
import { OPEN_STATE_MAX_AGE_MS, openStateLabel } from '@/lib/opening-hours';

/**
 * Most of these cases are about the WORDING, not the staleness, so they read a
 * payload that just arrived. The age is a required argument on purpose — a
 * default of 0 would let a real caller skip the staleness rule silently — and
 * this names the zero rather than hiding it in each call.
 */
const fresh = (state: OpenState | null | undefined) => openStateLabel(state, 0);

/**
 * The status cue's wording decision (T-155).
 *
 * The API decides open-or-closed; this function only picks which message says
 * so. The rule it must never break: **no state means no cue**, not "Cerrado".
 * The previous summary was deleted in T-128 precisely because a confidently
 * wrong "Closed" sends someone away from a restaurant that is open — restoring
 * the cue must not restore that failure by another route.
 */

it('shows NO cue when the API could not decide', () => {
  // Null is the API's "not knowable" — no structured periods, or no timezone.
  expect(fresh(null)).toBeNull();
  expect(fresh(undefined)).toBeNull();
});

it('shows NO cue for a cached object predating the field, rather than a wrong one', () => {
  // A response cached before `open_state` existed can rehydrate as junk; the
  // payload is validated at the edge but that stale object is not (the T-128 bug).
  expect(fresh({ open_now: 'yes' } as never)).toBeNull();
  expect(fresh({} as never)).toBeNull();
});

it('says open with the closing time when the venue closes today', () => {
  expect(fresh({ open_now: true, closes_at: '23:00', opens_at: null }))
    .toEqual({ key: 'place.openUntil', vars: { time: '23:00' }, open: true });
});

it('says just open — not "closes 00:00" — for a venue that never closes', () => {
  expect(fresh({ open_now: true, closes_at: null, opens_at: null }))
    .toEqual({ key: 'place.openNow', open: true });
});

it('says closed with the next opening when it is later the same day', () => {
  expect(fresh({ open_now: false, closes_at: null, opens_at: '19:00' }))
    .toEqual({ key: 'place.closedUntil', vars: { time: '19:00' }, open: false });
});

it('says just closed when the next opening is not today', () => {
  expect(fresh({ open_now: false, closes_at: null, opens_at: null }))
    .toEqual({ key: 'place.closedNow', open: false });
});

it('drops a malformed clock rather than interpolating it into the sentence', () => {
  // The server formats these; anything else reaching here is a bug upstream, and
  // "Abierto · cierra tomorrow" is worse than "Abierto".
  expect(fresh({ open_now: true, closes_at: '25:99', opens_at: null }))
    .toEqual({ key: 'place.openNow', open: true });
  expect(fresh({ open_now: false, closes_at: null, opens_at: 'later' as never }))
    .toEqual({ key: 'place.closedNow', open: false });
});

it('never re-derives the answer — it reports whatever open_now says', () => {
  // Deliberate contradiction: a closing time in the past, still open_now true.
  // The venue's timezone is not on the client and the device clock belongs to a
  // different one, so second-guessing the server here is exactly the bug class
  // T-128 removed.
  expect(fresh({ open_now: true, closes_at: '00:01', opens_at: null })?.open).toBe(true);
});

it('drops the cue once the payload is stale, rather than showing an old answer', () => {
  // The place query is persisted for 24h, so a cold start with no network can
  // paint an 11-hour-old payload. "Open · closes 23:00" at nine the next morning
  // is exactly the confidently-wrong "open" this feature exists to avoid,
  // arriving by a route the SERVER cannot close — it was right when it answered.
  const open = { open_now: true, closes_at: '23:00', opens_at: null };

  // Fresh, and right up to the boundary, the cue stands.
  expect(openStateLabel(open, 0)).not.toBeNull();
  expect(openStateLabel(open, OPEN_STATE_MAX_AGE_MS)).not.toBeNull();

  // A millisecond past it, there is no claim at all — not a "closed" claim.
  expect(openStateLabel(open, OPEN_STATE_MAX_AGE_MS + 1)).toBeNull();
  // Including for a CLOSED payload: staleness removes the cue, it does not
  // default to shut.
  expect(openStateLabel({ open_now: false, closes_at: null, opens_at: '19:00' }, 11 * 60 * 60 * 1000))
    .toBeNull();
});

it('treats a missing age as fresh, so a caller that cannot know is not punished', () => {
  expect(fresh({ open_now: true, closes_at: null, opens_at: null })).not.toBeNull();
});
