# T-071 — Personal collection model: my-map + filterable my-places list; per-user profiles

- **Phase:** M2 (discovery → ownership reframe) · **Estimate:** XL · **Status:** see tasks.json
- **Depends on:** T-013 (multi-place), T-037 (follows), T-062 (lists)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/MapController.php`,
  `apps/api/app/Models/Place.php`, `apps/api/app/Http/Controllers/Api/V1/ProfileController.php`,
  `apps/mobile/app/(main)/map.tsx`, `apps/mobile/app/(main)/feed.tsx` (→ my-places),
  `apps/mobile/app/users/[username]/*`, `apps/api/public/demo.html`
- **Spec refs:** `03-api-design.md#map`, `05-mobile-app.md`, ADR-071 in `07-risks-decisions.md`

## Context

Requested 2026-07-15: reframe the core model Instagram-style — **each user owns
their map + list; nothing global.** My map = only places I'm connected to (shared
and/or saved), not a global pin cloud. The chronological feed is removed and
replaced by a filterable **"my places"** list (country / type / tags) over the
same data as my map — two views of one dataset. Visiting another user shows THEIR
map + THEIR places list + THEIR public Lists, never mixed into mine. This resolves
the reported "removed from feed but still on the map" inconsistency: all content
is mine to curate.

## Product decisions (see ADR-071)

1. **What is "mine":** a place is mine if I **shared** it (published share by me,
   not soft-hidden) **OR saved** it to any of my lists. Saving from another user's
   map makes it mine. Reuses `place_lists` — no new "saved" table.
2. **Purely personal map:** the home map is always mine — the mine/following/all
   scope chips are removed. To see people I follow, visit their profile → their map.
3. **Soft, reversible removal:** removing one of my shared places from my
   collection soft-hides it (reuses `feed_dismissals`, now map + list aware).
   The pin leaves my map/list; the share & canonical place are kept; undo-able.
   Hard `DELETE /shares/{id}` stays a separate, explicit action.
4. **Canonical place stays global/deduped** under the hood; "my map" is the subset
   I'm connected to (a query scope, not a data copy) — no place migration.

## Implementation slices (each its own PR)

- **PR-1 — backend foundation:** `Place::scopeMine(User)` (shared∪saved, minus
  dismissed); `MapController` `filter=mine` → `scopeMine`; new `GET /me/places`
  (filterable keyset list: country, type/cuisine, tags, q, sort); new
  `GET /users/{username}/places` (their public places list) + `GET /users/{username}/lists`
  (their public lists); dismissals made map-aware via `scopeMine`. Tests + contract.
- **PR-2 — mobile personal map + My Places tab:** default authed map to mine;
  drop the scope chips; add a country facet; replace the Feed tab with a
  filterable "Mis lugares" list over `/me/places`; wire soft-hide remove.
- **PR-3 — mobile per-user profile:** other user's profile gains a map view +
  filterable places list + their public Lists.
- **PR-4 — pending venues actionable:** surface & resolve
  `review_meta_json.pending[]` on partially-published multi-place shares
  (closes the T-013 known limitation).
- **PR-5 — web demo parity:** personal map + my places + per-user views.

## Acceptance criteria

- [ ] Home map = ONLY the current user's places (shared and/or saved), not global
- [ ] Feed replaced by "my places" = a filterable list (country, type, tags) over the same data as my map
- [ ] Visiting another user shows THEIR map + THEIR places list + THEIR public Lists; never mixed into mine
- [ ] Removing one of my shares removes its pin from my map (soft, reversible)
- [ ] "Mine" implemented as shared ∪ saved (ADR-071)
- [ ] Canonical place stays global/deduped; "my map" is the connected subset
- [ ] Partially-published multi-place shares surface their pending venues for action
- [ ] Gates green per slice (API Pint/PHPStan/Pest + contract drift; mobile eslint/tsc/jest); web verified

## Progress

- **2026-07-15** — Planned. Decisions locked via ADR-071 (mine = shared∪saved;
  purely personal map; soft reversible removal). Backend + frontend current-state
  mapped. Building PR-1 (backend foundation) first.
