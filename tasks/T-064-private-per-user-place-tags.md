# T-064 — Private per-user tags/notes on places

- **Phase:** M3 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-030 (places API), T-033 (mobile place detail)
- **Target paths:** `apps/api/app/Models/UserPlaceTag.php`, `apps/api/app/Http/Controllers/Api/V1/UserPlaceTagController.php`, `apps/mobile/src/components/place/my-tags.tsx`
- **Spec refs:** `03-api-design.md#places`, `02-data-model.md`

## Context

Let a user attach **private labels** to a place that are visible **only to them** — e.g.
tag a café with "visitar a las 5" or "llevar a mamá". These are personal annotations, NOT
the public AI/global tags (`cuisines`/`vibe_tags`, materialized from extraction). They must
never be exposed to other users or aggregated into discovery.

Composes with lists (T-062): a natural follow-on is filtering saved places / a list by a
private tag.

## Data model

- `user_place_tags`: `id, user_id → cascade, place_id → cascade, label (string, trimmed,
  max ~40), timestamps`, `unique(user_id, place_id, label)`. Owner-only, never joined into
  any public query.

## API (auth:sanctum)

- `GET /me/places/{place}/tags` — my labels for this place.
- `POST /me/places/{place}/tags` `{label}` — add (idempotent per unique).
- `DELETE /me/places/{place}/tags/{tag}` (or by label) — remove.
- These live under `/me/...` so they're structurally impossible to read for another user;
  the place-detail resource does NOT include them (fetch separately when authed).

## Mobile

- A **"My tags"** section on place detail, shown **only to the authed owner**: existing
  private labels as removable chips + an inline add input (like the profile TagEditor).
- Optional: a filter on the Lists / saved-places view by a private tag.
- Localized (Spanish default).

## Acceptance criteria

- [x] `user_place_tags` migration + model, unique per (user, place, label), owner-only
- [x] `GET/POST/DELETE /me/places/{place}/tags` — owner CRUD
- [x] Private tags NEVER appear in the public place resource or any cross-user query
- [x] Mobile: "My tags" add/remove chips on place detail (authed owner only)
- [x] Isolation test: user A's tags are invisible to user B
- [x] Localized; owner CRUD + isolation tests

## Verification

- API: Pest — user B cannot see or delete user A's tags; add is idempotent per (user,place,label).
- Mobile: `tsc`/`lint`/`jest`; on device, add "visitar a las 5" and confirm it persists + is private.

## Log

- **2026-07-14 — DONE (PR #66, open · CI green · awaiting user merge).** Branch `feat/private-place-tags` off `main`.
  - **Backend:** `user_place_tags(user_id, place_id, label)` with a **case-insensitive functional unique index** on `(user_id, place_id, lower(label))` (the DB backstops the controller's `lower(label)` dedup exactly — even a mixed-case concurrent insert trips it). `UserPlaceTag` model + factory; `Place::userPlaceTags()`. `UserPlaceTagController` (auth:sanctum): `GET/POST /me/places/{place}/tags` + `DELETE /me/places/{place}/tags/{tag}`; owner-scoped (a foreign tag 404s — no existence oracle); `user_id`/`place_id` are guarded (server-derived, never mass-assigned); add is idempotent + case-insensitive, a race is caught on the unique index → 200.
  - **Deviation from the design note above:** rather than a separate fetch, private tags are exposed as an **owner-only `my_tags` field on the place detail**, resolved via the optional `sanctum` guard — present only for the authed viewer (their own labels), **omitted entirely for guests**. This still satisfies "NEVER in the public resource / any cross-user query" (guests get no key; the field only ever holds the caller's own tags) and mirrors the existing `is_own` pattern on reviews, saving a round-trip. `place.json` contract + generated TS updated.
  - **Mobile:** `usePlaceTags(slug)` add/remove hooks (invalidate the place detail); `MyTags` "My tags" section on the place screen, authed-only — removable chips + inline add field; a failed add keeps the text and shows an inline error. Localized `myTags.*` (es default / en).
  - **Gates:** API 436 Pest green, Pint + PHPStan L6 clean, contract drift check green; mobile 140 jest green, `tsc` + `expo lint` clean. Two adversarial reviews (backend + mobile) → SHIP; both 🟡 follow-ups folded in (functional unique index; inline add-error UX).
