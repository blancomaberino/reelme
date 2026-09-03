# T-158 — Tonight — the surface that answers 'where do I eat, here, now'

- **Phase:** GROW · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-155, T-156, T-157
- **Target paths:**
  `apps/mobile/app/(main)/`,
  `apps/api/app/Http/Controllers/Api/V1/`,
  `apps/api/app/Models/Builders/PlaceQueryBuilder.php`

## Why

The product's premise is that Instagram saves are useless because you never
revisit them. Reelmap currently stores better than Instagram and retrieves about
the same: a map of dots you triage by hand.

Tonight is the retrieval moment made into a surface -- the owner's query as one
screen: this zone, this kind of food, open right now, pick one. It is the reason
to open the app on a Friday at 20:30, and every euro downstream of that open is
zero without it.

## Acceptance

- The surface answers zone + dish + open-now against the viewer's position, and is reachable from a screen the user is already on -- with a test that presses that control (a render-in-test does not count as reachable)
- Changing any of the three inputs re-queries; asserted for each (test the loop, not the first paint)
- With the open-now filter on, places whose hours are unknown are EXCLUDED rather than guessed either way
- An empty result and a failed request render differently, and the failure state offers a retry
- A result set larger than one screen is virtualized, not `.map()`ed into a ScrollView
- Promoted placement (T-166) cannot reorder this list -- the guard test lives with T-166 and references this endpoint

## Notes

Filed 2026-09-03. The highest-value single build in the growth review. Sibling-first: reuse the existing map/list rendering primitives rather than introducing a second card or a second map. Depends on T-155 (hours), T-156 (distance) and T-157 (dishes) -- all three, because the query needs all three.
