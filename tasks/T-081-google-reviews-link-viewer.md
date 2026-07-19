# T-081 — Google reviews: in-app viewer + "read all on Google" deep link (finish gaps)

- **Phase:** M2 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-033 (mobile place detail), T-065 (Google Maps link + enrichments), T-059 (Google review cache)
- **Target paths:** `apps/mobile/app/place/[slug].tsx`, `apps/mobile/src/components/place/`, `apps/api/public/demo.html`, `apps/api/app/Http/Resources/PlaceResource.php`
- **Spec refs:** `05-mobile-app.md`, `03-api-design.md#places`

## Context

Requested 2026-07-17: a link to a place's Google reviews, or an in-app window to
read them.

**Honest scope — this is MOSTLY already shipped.** Don't rebuild it:

- `PlaceResource` already emits `google_reviews` (from `google_reviews_json`,
  cached snippets `{author, rating, text, relative_time, time, profile_photo_url}`)
  plus `rating.google {value, count}`.
- **Mobile place detail already renders the Google review snippets** and native
  Reelmap reviews (`app/place/[slug].tsx` reviews section).
- **T-065 already added the Google Maps profile link** (`google_place_id` →
  `maps/search/?api=1&query=…&query_place_id=…`, `safeUrl`/`isHttpUrl`-guarded).

### What's actually left

1. A dedicated **"Ver todas en Google / Read all on Google"** deep link that lands
   on the reviews view (not just the place page), when `google_place_id` exists.
2. **Web-demo parity** — confirm the demo drawer shows the snippet list + the link.
3. **Snippet-field completeness** — author, star rating, relative time, and photo
   per snippet, with clear Google attribution (ToS).

## Implementation

- Build the reviews deep link from `google_place_id` (guarded by the existing
  `safeUrl`/`isHttpUrl`); add the row on mobile detail and in `demo.html`.
- Verify the in-app snippet list shows author/stars/relative-time/photo; fill any
  gaps; keep Google attribution visible.
- Handle empty/stale states: no `google_place_id` → no link; snippets dropped by
  the ToS refresher (T-080/T-059) → hide the section, don't render an empty block.
- Localize es (default) / en.

## Acceptance criteria

- [ ] "Read all on Google" deep link on place detail (mobile + web) when
      `google_place_id` is present, http(s)-guarded
- [ ] In-app snippet viewer shows author, star rating, relative time, and photo
      with Google attribution; web demo reaches mobile parity
- [ ] No `google_place_id` → no link; no snippets → section hidden (no empty state
      rendered); localized es/en
- [ ] Tests: link built + guarded from `google_place_id`; snippets render; a place
      without Google data hides the section; mobile jest/tsc; web browser-check;
      gates green

## Verification

- Mobile: a place with `google_place_id` + snippets shows the viewer and the deep
  link; one without hides both. `tsc`/`lint`/`jest`.
- Web: demo drawer parity; `esc()`/URL guards intact.

> Pairs with **T-082**, which generalizes this single-source viewer into a
> multi-source aggregator (Google + native + Trustpilot). Keep this one small.
