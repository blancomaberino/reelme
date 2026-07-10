# T-036 — Public profiles API: users + influencers

- **Phase:** M3 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-030
- **Target paths:** `apps/api/app/Http/Controllers/ProfileController.php`, `apps/api/app/Http/Controllers/InfluencerController.php`
- **Spec refs:** [../03-api-design.md#users-influencers](../03-api-design.md#users-influencers)

## Context
M3 turns Reelmap into Instagram-like accounts: every user and every canonical influencer identity gets a public profile that exposes only published content. T-030 already shipped the Places API with sources + attribution, so the query building blocks (published `place_sources`, `PlaceResource`, map pin shaping) exist. This task adds the read-only public surface that T-037 (follows), T-038 (claiming) and T-039 (mobile profile screens) build on. App code lives in the separate app repo created by T-001 (`apps/api` in the monorepo).

## Implementation steps
1. **Routes** in `apps/api/routes/api.php` inside the `v1` prefix group, all `public` auth (work unauthenticated, per 03 §2.9):
   - `GET /api/v1/users/{user:username}` → `ProfileController@show` (route-model-bind on `username` citext column).
   - `GET /api/v1/users/{user:username}/map` → `ProfileController@map` (bbox params identical to §2.7 `GET /map/places`).
   - `GET /api/v1/influencers/{influencer}` → `InfluencerController@show`.
   - `GET /api/v1/influencers/{influencer}/map` → `InfluencerController@map`.
   Controllers under `App\Http\Controllers\Api\V1` per 03 §5 (the tasks.json paths are the physical files; namespace per spec).
2. **`ProfileController@show`**: return public profile + counters in the `{"data":..., "meta":...}` envelope: `username`, `name`, `bio`, `avatar_path` (as URL), `is_influencer`, counts (published shares, followers, following — follower counts return 0/omitted until T-037 lands; keep keys present so the contract is stable). Embed a cursor-paginated list of their **published** shares (`shares.status = 'published'`, joined through `published_place_source_id` → place) or expose it via `?include=shares`.
3. **Privacy via Laravel policies**: create `UserProfilePolicy` (or extend `UserPolicy`) with `viewProfile(?User $viewer, User $subject)`:
   - `users.is_public = false` → only the owner sees the full profile; others get 404 (`not_found` error code — do not leak existence with 403).
   - Soft-deleted users → 404.
   - Never serialize: `email`, `preferred_analysis_model`, Stripe columns, role flags other than `is_influencer`. Enforce in a dedicated `PublicUserResource` (never reuse the `/me` resource).
4. **`ProfileController@map`**: places from the user's published shares only — `places` joined via `place_sources` → `shares` where `shares.user_id = ?` AND `shares.status = 'published'` AND `places.status = 'active'` (resolve `merged` via `merged_into_place_id`, single hop). Reuse the T-030/T-031 pin/cluster response shape. Gate on the same `is_public` policy.
5. **`InfluencerController@show`**: influencer profile per §2.9 — `platform`, `handle`, `display_name`, `avatar_url`, `claimed` boolean (derived from `claimed_by_user_id`, never expose the raw user id; if claimed and that user is public, embed their public handle), `follower_count_cached`, place count (distinct active places reachable via `source_posts.influencer_id` → `place_sources` on published shares).
6. **`InfluencerController@map`**: all places sourced from this influencer's posts — `places` via `place_sources` → `source_posts.influencer_id = ?`, published shares only, same pin/cluster shape as step 4. Influencer profiles are always public (they exist independently of accounts; there is no `is_public` on `influencers`).
7. **Resources**: `PublicUserResource`, `InfluencerResource`, reuse `PlaceResource`/pin resource from T-030. ULID-style string ids in JSON per 03 §1.
8. **Contracts**: add/update JSON Schemas in `packages/contracts/schemas` for both profile responses and regenerate TS types in the same PR (03 §5).
9. **Pest feature tests** per endpoint (03 §5): happy path, private-user 404, unpublished-share exclusion, validation error shape, rate-limit headers present (public reads: 30/min per IP).

## Acceptance criteria
- [ ] `GET /api/v1/users/{username}` returns 200 with public profile fields + counters and only shares with `status = published`, in the `{"data":...,"meta":...}` envelope with cursor pagination for the share list.
- [ ] `GET /api/v1/users/{username}` returns 404 for `is_public = false` users (unless the authenticated viewer is the owner) and for soft-deleted users; response never contains `email`, Stripe fields, or admin/restaurant role flags.
- [ ] `GET /api/v1/users/{username}/map` returns only places evidenced by that user's published shares; merged places resolve to their terminal place; response shape matches `GET /map/places`.
- [ ] `GET /api/v1/influencers/{id}` returns profile with platform handle, display name, `claimed` status, cached follower count, and promoted-place count; `claimed_by_user_id` is never serialized raw.
- [ ] `GET /api/v1/influencers/{id}/map` returns every active place with a `place_sources` row tracing to that influencer's `source_posts`, published shares only.
- [ ] Shares in `pending`/`fetching`/`analyzing`/`review`/`failed`/`rejected` never appear on any public endpoint (test seeds one share per status and asserts exclusion).
- [ ] All four endpoints work unauthenticated; Pest tests cover happy path, privacy denial, error shape, and rate-limit headers.
- [ ] JSON Schemas added to `packages/contracts/schemas` and TS types regenerated.

## Verification
```bash
cd apps/api
composer test -- --filter=Profile   # ProfileControllerTest green
composer test -- --filter=Influencer
vendor/bin/pint --test && vendor/bin/phpstan
```
Manual (against local API with seeded data):
```bash
curl -s http://localhost:8000/api/v1/users/marcelo | jq .data          # public profile, published shares only
curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/api/v1/users/private_user   # → 404
curl -s "http://localhost:8000/api/v1/influencers/01J.../map?bbox=-9.2,38.69,-9.1,38.75&zoom=13" | jq .data.pins
```
Expected: profile shows no email/Stripe fields; influencer map pins match places seeded via that influencer's source_posts.

## Gotchas
- **404 vs 403 for private profiles:** returning 403 confirms the username exists — use 404 to avoid handle enumeration; the error `code` must be `not_found`.
- **Merged places:** a user's published share may point at a place later merged away. Follow `merged_into_place_id` (exactly one hop per data-model §4.3) or their map shows dead pins.
- **Username routing:** `username` is `citext` — route binding is case-insensitive at the DB level, but write a test for `/users/MARCELO` to prove it.
- **Counter fields before T-037:** followers/following counters ship as stable keys now (zero) so the mobile contract doesn't churn when follows land; do not compute them with `COUNT(*)` per request later — T-037 adds counter caches.
- **Don't reuse the `/me` resource** for public profiles; one forgotten field there becomes a GDPR/privacy leak here.
- **N+1 on share lists:** eager-load `share.publishedPlaceSource.place` and `share.sourcePost.influencer`; assert query count in a test.
