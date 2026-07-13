# T-062 — Lists: save a place to a personal collection

- **Phase:** M3 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-030 (places API), T-033 (mobile place detail), T-010 (mobile auth)
- **Target paths:** `apps/api/app/Models/PlaceList.php`, `apps/api/app/Http/Controllers/Api/V1/PlaceListController.php`, `apps/mobile/app/lists/`, `apps/mobile/src/components/place/save-to-list.tsx`
- **Spec refs:** `03-api-design.md#places`, `05-mobile-app.md#screen-inventory`

## Context

Users want to **save places into named lists** so they can group them and view them
together later. The motivating use case: while browsing, save several places in a country
you plan to visit into one list; when you travel there, open the list and see all of them
together on a map.

This is personal curation (distinct from follows/feed). It's the foundation for **T-063
(share lists)** and composes with **T-064 (private tags)**.

## Data model

- `place_lists`: `id, user_id → users cascade, name, slug (unique per owner), is_public
  default false, timestamps`. `slug` is stable + used by the public share route later.
- `place_list_items`: `id, place_list_id → cascade, place_id → cascade, note (nullable),
  position (int), timestamps`, `unique(place_list_id, place_id)`.
- All reads/writes owner-scoped via the authed user.

## API (auth:sanctum)

- `GET /me/lists` — the user's lists (name, item count, is_public).
- `POST /me/lists` `{name}` — create; `PATCH /me/lists/{list}` `{name?, is_public?}`;
  `DELETE /me/lists/{list}`.
- `GET /me/lists/{list}` — the list + its places as **map-ready summaries** (reuse
  `PlaceSummaryResource` / MapViewport so the mobile list-map needs no extra query).
- `POST /me/lists/{list}/places/{place}` `{note?}` — add (idempotent `firstOrCreate`);
  `DELETE /me/lists/{list}/places/{place}` — remove.

## Mobile

- **Save affordance** on place detail (a bookmark/heart button) → a bottom-sheet **list
  picker** listing the user's lists (checkmark if the place is in it) with a **create-new**
  row. Toggling adds/removes optimistically.
- **Lists screen** (reachable from Profile) — the user's lists; tap one to open it.
- **List detail** — the list's places as a scrollable list **and** on a map (MapViewport),
  each tappable through to place detail.
- Localized (Spanish default).

## Acceptance criteria

- [ ] `place_lists` + `place_list_items` migrations, models, owner scoping
- [ ] List CRUD + add/remove endpoints, all owner-scoped; add is idempotent; unique(list,place)
- [ ] `GET /me/lists/{list}` returns map-ready place summaries
- [ ] Mobile: Save → list picker (with create), Lists screen, list detail (list + map)
- [ ] End-to-end: save several places to one list, open it, see them together on a map
- [ ] Localized (es default); tests: API owner-scope + dedupe, mobile save flow

## Verification

- API: Pest — owner cannot see/modify another user's list; add is idempotent; removing works.
- Mobile: `tsc`/`lint`/`jest`; on-device Maestro — save a place, open Lists → the list → its map.
