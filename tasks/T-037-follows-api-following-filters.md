# T-037 — Follows API + following filters

- **Phase:** M3 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-036
- **Target paths:** `apps/api/app/Models/Follow.php`, `apps/api/app/Http/Controllers/FollowController.php`
- **Spec refs:** [../02-data-model.md#follows](../02-data-model.md#follows), [../03-api-design.md#follows](../03-api-design.md#follows)

## Context
With public user and influencer profiles live (T-036), users need to follow them and filter the map and feed down to accounts they follow — the M3 exit criterion is "Following an influencer filters map/feed; follow triggers a push notification." This task adds the polymorphic `follows` table (data-model §3.11, migration slot 15), the follow/unfollow API (03 §2.10), the `following` scope on `/map/places` and `/feed`, and follower counter caches. App code lives in the separate app repo created by T-001 (`apps/api`).

## Implementation steps
1. **Migration** `create_follows_table` per data-model §3.11: `id` bigint identity; `follower_user_id` FK → users (CASCADE); `followee_type varchar(32)` (morph alias `user` | `influencer`); `followee_id bigint` (no DB FK — polymorphic); timestamps. Indexes: `unique(follower_user_id, followee_type, followee_id)`, `index(followee_type, followee_id)`.
2. **Counter caches**: add nullable-safe integer columns in the same PR — `users.followers_count`, `users.following_count`, `influencers.followers_count` (default 0). Maintain them with `DB::table(...)->increment()/decrement()` inside the same transaction as the follow insert/delete (not model events on their own — see Gotchas).
3. **Model** `App\Models\Follow`: `followee()` as `morphTo`; register morph aliases in a service provider so class names never hit the DB:
   ```php
   Relation::enforceMorphMap([
       'user' => User::class,
       'influencer' => Influencer::class,
   ]);
   ```
   Add `follows()`/`followers()` relations on `User` and `followers()` on `Influencer` (`morphMany(Follow::class, 'followee')`).
4. **Routes** (03 §2.10, all `auth:sanctum`): `POST /api/v1/follows`, `DELETE /api/v1/follows/{follow}`, `GET /api/v1/me/follows`, `GET /api/v1/me/followers`. Controller `App\Http\Controllers\Api\V1\FollowController` (physical file per target path).
5. **`store`**: validate `{followable_type: "user"|"influencer", followable_id}` (this is the wire field name per 03 §2.10; map it onto the `followee_*` DB columns). Rules:
   - target must exist (and for users: pass the T-036 public-visibility policy);
   - cannot follow self (`followable_type=user && followable_id == auth id` → 422; also block following an influencer whose `claimed_by_user_id` is the auth user);
   - duplicate → 409 `conflict` returning the existing follow id (idempotent-friendly).
   Use `firstOrCreate` on the unique triple inside a transaction with the counter increments.
6. **Notification on follow**: fire a `NewFollower` Laravel notification (database + Expo push channels, reusing the T-027 push channel) to the followee — for `user` targets directly; for `influencer` targets only when `claimed_by_user_id` is set. `data` payload: `{type: "social.follow", url: "/users/{follower_handle}"}` per 05 §5.2. Queue it (`ShouldQueue`).
7. **`destroy`**: policy `FollowPolicy@delete` — only the follower may unfollow; decrement counters in the same transaction; return 204.
8. **`following` filters**:
   - `GET /api/v1/map/places?filter=following` (03 §2.7): restrict places to those with a `place_sources` row whose `share.user_id` is a followed user **or** whose `source_post.influencer_id` is a followed influencer. Implement as a reusable query scope (e.g. `Place::scopeFollowedBy(User $u)`) built from two `whereIn` subqueries on the `follows` table; requires auth (401 when `filter=following` unauthenticated).
   - `GET /api/v1/feed?scope=following` (03 §2.8): same subqueries applied to published shares, reverse-chron, cursor-paginated.
   - Accept `following_only=true` as an alias for these params if T-034's feed already shipped that name — keep one canonical param (`filter=following` / `scope=following` per 03) and treat the alias as deprecated.
9. **`GET /me/follows` / `GET /me/followers`**: cursor-paginated; eager-load followees (`with('followee')`) and serialize via the T-036 public resources.
10. **Contracts + tests**: JSON Schemas for follow request/response; Pest tests for happy path, self-follow 422, duplicate 409, unfollow authz denial, counter cache values, notification dispatched (`Notification::fake()`), and both filters returning only followed content.

## Acceptance criteria
- [ ] `follows` migration matches data-model §3.11 exactly: unique(`follower_user_id`,`followee_type`,`followee_id`), index(`followee_type`,`followee_id`), FK cascade on `follower_user_id`, no FK on `followee_id`.
- [ ] `POST /api/v1/follows` follows a user or an influencer; morph map stores `user`/`influencer` aliases (never FQCNs) in `followee_type`.
- [ ] Self-follow rejected with 422 `validation_failed`; duplicate follow returns 409 `conflict`; unfollowing someone else's follow returns 403 `forbidden`.
- [ ] `DELETE /api/v1/follows/{id}` removes the row and returns 204; `GET /me/follows` and `GET /me/followers` are cursor-paginated and typed.
- [ ] Following triggers a queued `NewFollower` notification (database + push) to followed users and to claimed influencers' owners; unclaimed influencers produce no notification and no error.
- [ ] `GET /map/places?filter=following` and `GET /feed?scope=following` return only content traceable to followed users/influencers and 401 when unauthenticated.
- [ ] `followers_count`/`following_count` counter caches update on follow and unfollow inside the same DB transaction; concurrent double-submit cannot double-count (unique index + firstOrCreate proven by test).

## Verification
```bash
cd apps/api
php artisan migrate && composer test -- --filter=Follow
composer test -- --filter='Map|Feed'   # following filter tests
vendor/bin/pint --test && vendor/bin/phpstan
```
Manual:
```bash
TOKEN=... # user A
curl -s -X POST http://localhost:8000/api/v1/follows -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"followable_type":"influencer","followable_id":"01J..."}' | jq
# repeat same call → 409; then:
curl -s "http://localhost:8000/api/v1/map/places?bbox=-9.2,38.69,-9.1,38.75&zoom=13&filter=following" \
  -H "Authorization: Bearer $TOKEN" | jq '.data.pins | length'   # only the followed influencer's places
php artisan horizon &  # confirm NewFollower job processed; followee device receives Expo push
```

## Gotchas
- **Polymorphic unique constraint:** uniqueness must be the DB index, not an app-level check — two concurrent `POST /follows` will both pass an `exists` check; catch the unique-violation exception and return the 409 instead of a 500.
- **Counter cache race:** never `followers_count = count(...)` read-modify-write in PHP; use atomic `increment()`/`decrement()` in the same transaction as the insert/delete, and make unfollow idempotent (decrement only when a row was actually deleted) or counts drift negative.
- **Wire name vs column name:** the API contract says `followable_type/followable_id` (03 §2.10) while the table uses `followee_type/followee_id` (02 §3.11). Keep both exactly as spec'd; translate in the FormRequest, and don't "fix" either side.
- **Morph map is load-bearing:** without `enforceMorphMap`, `followee_type` stores `App\Models\User` and every query/spec fixture breaks; enforce (not just define) so unmapped morphs throw in tests.
- **Following-filter query cost:** the two-subquery `whereIn` over `follows` is fine at M3 scale but must ride the existing `place_sources`/`shares` indexes — check `EXPLAIN` with 10k seeded places (M2 perf budget: <300ms p95 on map).
- **Notification fan-out:** notify the followee, never the followers list — there is no fan-out on follow. Keep it queued so the API responds in one round trip.
