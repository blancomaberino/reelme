# T-063 — Share lists: public/shareable place collections

- **Phase:** M3 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-062 (lists)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/PlaceListController.php`, `apps/mobile/app/list/[slug].tsx`
- **Spec refs:** `03-api-design.md#places`

## Context

Extend **T-062** so a user can **share a list** with others (e.g. send a friend your
"Lisbon food" list). A list toggled public gets a stable, shareable link that opens a
read-only view; an authed viewer can save a copy to their own lists.

## API

- `GET /lists/{slug}` — **public read** when the list `is_public`: owner attribution, the
  list's places (map-ready), read-only. **404 when private** — put the privacy check in
  the FormRequest `authorize()` (before validation) so a private list is indistinguishable
  from a missing one (mirror the T-036 public-profile existence-oracle fix).
- Reuse `PATCH /me/lists/{list}` `{is_public}` from T-062 to toggle.
- `POST /me/lists/{list}/copy` (or `POST /me/lists?copy_from={slug}`) — clone a public
  list into the authed user's own lists.

## Mobile

- A **Share** button on a list (owner) → toggles public if needed, then opens the native
  share sheet with `reelmap://list/{slug}` (+ a web URL).
- Opening a shared link routes to a **read-only list view** (`app/list/[slug].tsx`) showing
  the owner, places, and map. Authed non-owners get a **"Save a copy"** action.
- Deep-link handling via the existing `+native-intent` / scheme wiring.

## Acceptance criteria

- [ ] `GET /lists/{slug}` public when `is_public`, 404 when private (privacy in `authorize()`)
- [ ] Owner can toggle public/private and obtain a shareable deep link + web URL
- [ ] Mobile: Share opens the OS sheet; a shared link opens a read-only list; authed viewers can save a copy
- [ ] Private lists never leak; a shared list exposes only published/visible places
- [ ] Localized; tests: public/private visibility, copy flow

## Verification

- API: Pest — private list 404s for a stranger; public list returns places; copy clones items.
- Mobile: open a shared `reelmap://list/{slug}` on the simulator/device → read-only view; save-a-copy adds it.
