# T-030 — Places API: index/show with filters + sources + attribution

- **Phase:** M2 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-023
- **Target paths:** `apps/api/app/Http/Controllers/PlaceController.php`, `apps/api/app/Http/Resources/`
- **Spec refs:** [../03-api-design.md#places](../03-api-design.md) (§2.6, §1 conventions), [../02-data-model.md#places](../02-data-model.md) (§3.8–3.9)

## Context

T-023 delivered `places` and `place_sources` (the provenance backbone). This task exposes them: a public, cursor-paginated place index with filters, and a place detail endpoint that surfaces every source post with sharer + influencer attribution — the M2 exit criterion "place detail shows extracted data, every source post (link-out to original), influencer, sharer". It unblocks T-033 (mobile Place detail) and T-034 (feed/search). App code lives in the separate app repo created by T-001; controllers go under `App\Http\Controllers\Api\V1`.

## Implementation steps

1. **Routes.** In `apps/api/routes/api.php` (v1 group, public):
   - `GET /places` → `PlaceController@index`
   - `GET /places/{place:slug}` → `PlaceController@show` (route-model binding on `slug` — globally unique per data model; tasks.json specifies slug-based show even though §2.6 writes `:id`, slug is canonical here)
   - `GET /places/{place:slug}/sources` → `PlaceController@sources`
2. **Index filters** (FormRequest `PlaceIndexRequest`), per §2.6: `q` (name prefix/trigram match on `normalized_name`; full search belongs to T-031's `/search`), `tags[]` (slugs, via `place_tag` — schema-guard until T-031 lands), `near=lat,lng` + `radius_m` (default 2000, max 50000) using `ST_DWithin(location, ST_MakePoint(lng,lat)::geography, radius_m)`, `influencer_id` (places having a place_source whose source_post belongs to that influencer), `sort` in `recent|popular|distance` (`distance` requires `near`; `popular` = `shares_count` desc; default `recent` = `created_at` desc). Only `status = 'active'` places are listed.
3. **Cursor pagination.** Laravel `cursorPaginate($limit)` with `limit` 1–100 default 25; emit `meta.pagination: {next_cursor, prev_cursor, limit}` per §1. Note: `cursorPaginate` requires a deterministic ORDER BY — always append `id` as tiebreaker; distance sort needs the `ST_Distance` expression selected as an aliased column to be cursorable (or fall back to offset-free keyset on `(distance, id)`).
4. **Show.** `GET /places/{slug}`: full place (name, slug, lat/lng extracted via `ST_Y/ST_X`, address fields, `country_code`, `google_place_id`, `cuisine_primary`, `price_range`, `phone`, `website`, `opening_hours_json`, tags, `shares_count`, `avg_extraction_confidence`, `status`). Support `?include=sources,offers` (§2.6): `sources` embeds the sources payload below; `offers` is accepted-but-empty until M4. **Merged handling:** if `status = merged`, follow `merged_into_place_id` (single hop) and return the terminal place with `meta.redirected_from: <requested slug>`. `pending`/`hidden` places 404 publicly.
5. **Sources endpoint** (`GET /places/{slug}/sources`, cursor paginated): one item per `place_sources` row —
   - source post: `platform`, `url` (original post link-out), `caption` excerpt, `posted_at`, thumbnail media asset URL (signed)
   - influencer: `id`, `handle`, `display_name`, `avatar_url` (from `source_posts.influencer_id`)
   - sharer: `id`, `username`, `name`, avatar (from `place_sources.share.user`) — respect `users.is_public`
   - `extraction_snapshot_json` highlights (dishes, tags) and `is_primary`
6. **API Resources** in `apps/api/app/Http/Resources/`: `PlaceResource`, `PlaceSummaryResource` (index), `PlaceSourceResource`, `InfluencerSummaryResource`, `UserSummaryResource`. All responses use the `{"data": ..., "meta": {...}}` envelope; relations embedded (no `included` documents). Add/extend matching JSON Schemas in `packages/contracts/schemas` (e.g. `place.json`) in the same PR and regenerate TS types.
7. **Eager loading.** Index: `with('tags')`. Sources: `with(['sourcePost.influencer', 'share.user', 'sourcePost.mediaAssets' => thumbnail-kind])`. Enable `Model::preventLazyLoading()` in tests.
8. **Tests** (Pest feature, per endpoint): happy path; each filter incl. `near`+`radius_m` geo assertions (seed points inside/outside radius); cursor pagination walk (two pages, stable ordering, no duplicates/gaps); merged place resolves to terminal; pending/hidden 404; validation error envelope; rate-limit headers; contract test that `PlaceResource` output validates against the JSON schema.

## Acceptance criteria

- [ ] `GET /api/v1/places` returns active places, cursor-paginated (`meta.pagination.next_cursor`), `limit` capped at 100
- [ ] Filters `q`, `tags[]`, `near`+`radius_m`, `influencer_id`, `sort=recent|popular|distance` all work and combine; `distance` without `near` → 422
- [ ] `GET /api/v1/places/{slug}` returns the full place shape incl. lat/lng, address, hours, tags, counters
- [ ] `?include=sources` embeds sources; unknown includes are rejected (422)
- [ ] Requesting a merged place returns the terminal place (single-hop `merged_into_place_id`) with `meta.redirected_from`
- [ ] `pending` and `hidden` places return 404 on public show/index
- [ ] `GET /places/{slug}/sources` lists every place_source with: original post URL link-out, platform, caption/thumbnail, influencer attribution, sharer attribution, `is_primary`
- [ ] All payloads use the `{data, meta}` envelope and match JSON Schemas in `packages/contracts/schemas` (contract test green)
- [ ] No N+1 queries (lazy-loading prevention active in tests)
- [ ] Pest coverage: happy path, filter combos, pagination walk, authz-free public access, validation error shape, rate-limit headers

## Verification

```bash
cd apps/api
php artisan test --filter=Place
curl -s "http://localhost:8000/api/v1/places?near=38.7169,-9.1355&radius_m=3000&sort=distance&limit=5" | jq '.data[0], .meta.pagination'
SLUG=$(curl -s "http://localhost:8000/api/v1/places?limit=1" | jq -r '.data[0].slug')
curl -s "http://localhost:8000/api/v1/places/$SLUG?include=sources" | jq '.data.sources[0].influencer, .data.sources[0].sharer, .data.sources[0].source_post.url'
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse
```

Expected: distance-sorted results nearest-first; sources item shows influencer handle, sharer username, and the original Instagram/TikTok URL.

## Gotchas

- **Cursor pagination + dynamic sort:** Laravel cursors encode the ORDER BY columns; switching `sort` mid-pagination with an old cursor throws — validate that cursor and sort are consistent (return 422 `validation_failed` on mismatch) rather than 500.
- **Distance sort cursor:** ordering by a raw `ST_Distance` select needs the expression aliased (`->orderBy('distance')` on a selected alias) or `cursorPaginate` can't serialize it; always add `->orderBy('id')` as tiebreaker.
- **Merged chains:** the data model guarantees single-hop, but code defensively: loop with a hop limit of 1 and log if a second hop is ever needed.
- **Sharer privacy:** a sharer with `is_public = false` must appear anonymized (`"sharer": null` or handle-less) in sources — the attribution row is public content.
- **Signed thumbnail URLs:** generating per-item signed R2 URLs in a 25-item sources page is fine, but memoize disk/temporary-URL calls; never expose raw storage paths.
- **`tags[]` before T-031:** pivot tables may not exist yet — schema-guard the filter (see T-029) and enable the test when T-031 merges.
