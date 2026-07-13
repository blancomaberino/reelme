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

- [ ] `user_place_tags` migration + model, `unique(user, place, label)`, owner-only
- [ ] `GET/POST/DELETE /me/places/{place}/tags` — owner CRUD
- [ ] Private tags NEVER appear in the public place resource or any cross-user query
- [ ] Mobile: "My tags" add/remove chips on place detail (authed owner only)
- [ ] Isolation test: user A's tags are invisible to user B
- [ ] Localized; owner CRUD + isolation tests

## Verification

- API: Pest — user B cannot see or delete user A's tags; add is idempotent per (user,place,label).
- Mobile: `tsc`/`lint`/`jest`; on device, add "visitar a las 5" and confirm it persists + is private.
