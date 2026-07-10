# T-031 — Tags + Scout/Meilisearch search

- **Phase:** M2 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-023
- **Target paths:** `apps/api/app/Models/Tag.php`, `apps/api/config/scout.php`
- **Spec refs:** [../02-data-model.md#tags](../02-data-model.md) (§2 TagKind, §3.10), [../03-api-design.md#tags-search](../03-api-design.md) (§2.11)

## Context

Extraction results (T-021/T-024) carry cuisines, dishes and vibe tags that are currently only stored inside `extraction_snapshot_json`. This task materializes them as first-class `tags` + `place_tag` rows on publish, and wires Meilisearch (already in the stack: Scout + self-hosted Meilisearch per 01-architecture §1) for typo-tolerant search over places, tags, and influencers. It unblocks the tag filters in T-029/T-030/T-032 and the Search screen in T-034. App code lives in the separate app repo created by T-001.

## Implementation steps

1. **Migrations** (M2 migration order #14 per 02-data-model §6): `tags` (`kind` varchar(16) CHECK against `TagKind` values, `name` varchar(80), `slug` varchar(96), unique(`kind`,`slug`)) then `place_tag` pivot (no id/timestamps: `place_id`, `tag_id` FKs cascade, `source` varchar(16) default `'extraction'` in `extraction|manual|owner`, `confidence` numeric(4,3) nullable; PK(`place_id`,`tag_id`), index(`tag_id`)).
2. **Enum + models.** `App\Enums\TagKind: string` — `cuisine, vibe, dish, diet, other`. `Tag` model (casts `kind`), `belongsToMany(Place::class)->withPivot('source','confidence')`; inverse `tags()` on `Place`. Factory + slug normalization (`Str::slug`, lowercase, unaccent) on a `saving` observer or mutator.
3. **Populate from extraction on publish.** In the `PublishShare` job (T-024), after the place_source is created: map extraction fields → tags with `firstOrCreate(['kind' => ..., 'slug' => ...])`:
   - extraction `cuisine` → `kind=cuisine` (also sets `places.cuisine_primary` if null)
   - extraction `dishes[]` → `kind=dish`
   - extraction `tags[]` → classify against a small curated cuisine/diet slug list, else `kind=other`
   Attach via `syncWithoutDetaching` with pivot `source='extraction'`, `confidence` from the analysis run; on re-attach keep **max** confidence (mirrors the merge rule in 02-data-model §4.3).
4. **Scout + Meilisearch.** `composer require laravel/scout meilisearch/meilisearch-php http-interaction` deps; publish `config/scout.php` (`driver=meilisearch`, `MEILISEARCH_HOST/KEY` in `.env.example`, `scout.queue = true` on the `ingest`-adjacent default queue). Make `Place`, `Tag`, `Influencer` searchable:
   - `Place::toSearchableArray()`: `name`, `normalized_name`, `city`, `country_code`, `cuisine_primary`, `price_range`, tag slugs, plus `_geo: {lat, lng}` for Meilisearch geo; `shouldBeSearchable()` → `status === 'active'` only.
   - Configure index settings via a settings sync step (filterable: `price_range`, `cuisine_primary`, `tags`, `country_code`; sortable: `shares_count`; searchable order: name > tags > city).
5. **Endpoints** (public, v1):
   - `GET /tags` → `TagController@index`: `?q=` prefix search on slug/name, `?popular=1` orders by `place_tag` usage count, cursor-paginated.
   - `GET /search` → `SearchController`: `?q=` required, `?types=places,users,influencers,tags` (default all). Use Meilisearch **multi-search** (one HTTP round trip) across the `places`, `influencers`, `tags` indexes (`users` type returns empty until M3 profiles); response `{data: {places: [], influencers: [], tags: []}, meta: {query, took_ms}}` with summary resources from T-030.
6. **Reindex command.** `php artisan reelmap:search:reindex` — flushes + `scout:import` for each searchable model and re-pushes index settings; document in the API README (needed after settings changes and the 10k seeder).
7. **Tests.** Pest: publish flow creates expected tag kinds + pivot confidence; re-publish keeps max confidence; `GET /tags?q=nood` prefix match; `GET /search` shape + type filtering. For search tests either run a Meilisearch service container (CI job already has services per T-006 — add `getmeili/meilisearch`) with `scout.queue=false` and explicit index-settle waits, or use `SCOUT_DRIVER=collection` for pure-shape tests and tag real-Meili tests `@group meilisearch`.

## Acceptance criteria

- [ ] `tags` and `place_tag` tables exactly per 02-data-model §3.10 (constraints, indexes, CHECK on kind)
- [ ] `TagKind` PHP-backed enum with `cuisine, vibe, dish, diet, other`
- [ ] Publishing a share creates/attaches tags from the extraction result with `source='extraction'` and per-tag confidence; duplicate attach keeps max confidence
- [ ] `places.cuisine_primary` backfilled from the extraction cuisine when null
- [ ] `Place`, `Tag`, `Influencer` indexed in Meilisearch via Scout; only `active` places are searchable; place documents include `_geo`
- [ ] `GET /api/v1/tags` supports `?q=` prefix search and `?popular=1`, cursor-paginated, `{data, meta}` envelope
- [ ] `GET /api/v1/search?q=&types=` federates places, tags, influencers via one multi-search call; typo-tolerant (e.g. `nodle` finds "noodles")
- [ ] `reelmap:search:reindex` rebuilds all indexes + settings idempotently
- [ ] Tag filters in `/places` and `/map/places` (T-029/T-030 schema guards) are now active with passing tests
- [ ] Tests green in CI with a Meilisearch service container (or collection driver for shape tests)

## Verification

```bash
cd apps/api
php artisan migrate
php artisan test --filter="Tag|Search"
php artisan reelmap:search:reindex
curl -s "http://localhost:8000/api/v1/tags?q=noo" | jq '.data'
curl -s "http://localhost:8000/api/v1/search?q=nodle&types=places,tags" | jq '.data.places[0].name, .data.tags'
# publish a fixture share (T-028 pipeline test helpers), then:
php artisan tinker --execute="dump(App\Models\Place::latest()->first()->tags->pluck('slug','pivot.confidence'))"
```

Expected: typo query `nodle` returns the noodle place; published place has cuisine/dish/other tags with confidences.

## Gotchas

- **Meilisearch index sync in tests:** Scout indexing is queued and Meilisearch itself is async — a test that indexes then immediately searches sees stale results. Set `scout.queue=false` in `phpunit.xml` and poll the Meilisearch task queue (`waitForTask`) before asserting; never `sleep()`.
- **Index name collisions between test runs/environments:** set `scout.prefix` per env (`reelmap_testing_`) so parallel CI jobs and local dev don't clobber each other; flush prefixes in test setup.
- **Seeder vs Scout:** the T-029 10k seeder must bulk-insert with `withoutSyncingToSearch()` or every seed run floods the queue and Meilisearch; reindex explicitly when search-over-seeded-data is wanted.
- **`_geo` field:** Meilisearch requires `_geo.lat/lng` as floats and geo must be in the filterable/sortable settings before documents rely on it — push settings *before* import in the reindex command.
- **Tag explosion:** raw extraction tags are free text; slugify + trim + cap length (96) and drop empty/1-char tags or the tags table fills with junk. Keep the cuisine/diet classification list curated and small.
- **Merged places:** when T-035 merges B into A, B must be de-indexed (`shouldBeSearchable` false once `status=merged`) — Scout handles it on save, but the merge action must `save()` B, not bulk-update silently (`Place::withoutSyncingToSearch` on bulk rehome, then explicit sync of A and B).
