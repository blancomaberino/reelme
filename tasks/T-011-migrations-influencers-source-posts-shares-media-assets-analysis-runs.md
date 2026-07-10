# T-011 — Migrations: influencers, source_posts, shares, media_assets, analysis_runs

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-003
- **Target paths:** `apps/api/database/migrations/`, `apps/api/app/Models/`, `apps/api/app/Enums/`
- **Spec refs:** [02-data-model.md](../02-data-model.md)

## Context

M0 delivered the Laravel 13 API scaffold with Postgres+PostGIS and the `users` table (T-003). This task lays the M1 data foundation: the five entities the ingest/analysis pipeline reads and writes. Everything downstream in M1 (adapters, share API, jobs, ModelRouter) depends on these tables, models, enums, and factories existing. Application code lives in the separate app repo created by T-001 (under `apps/api/`), NOT this plans folder.

Deliberately **out of scope**: `platform_accounts` (T-015), `places`/`place_sources` (T-023), and the `shares.published_place_source_id` column — per 02-data-model.md §6 the `shares ⇄ place_sources` circular dependency is broken by adding that column in a later migration (T-023 territory). Do not add it here.

## Implementation steps

1. **Verify extensions.** T-003 must have enabled `postgis`, `pg_trgm`, `unaccent`, `citext` (02 §6 M0 step 1). Start this task's first migration with a defensive `DB::statement('CREATE EXTENSION IF NOT EXISTS citext')` (and the other three) so it is order-safe.
2. **Create PHP-backed string enums** in `apps/api/app/Enums/` exactly per 02 §2 (namespace `App\Enums`):
   ```php
   enum Platform: string { case Instagram = 'instagram'; case X = 'x'; case Tiktok = 'tiktok'; case Youtube = 'youtube'; }
   enum PostPrivacy: string { /* public, private, unknown */ }
   enum FetchStatus: string { /* pending, fetching, fetched, manual, failed */ }
   enum ShareStatus: string { /* pending, fetching, analyzing, review, published, failed, rejected */ }
   enum MediaKind: string { /* video, audio, keyframe, thumbnail, screen_recording */ }
   enum AnalysisEngine: string { /* local, openrouter */ }
   enum AnalysisStatus: string { /* queued, running, succeeded, failed */ }
   ```
3. **Migrations, FK-safe order** (one file each, order per 02 §6 M1 — skip `platform_accounts`, it is T-015):
   1. `create_influencers_table` — columns per 02 §3.3: `platform varchar(16)`, `handle citext`, `display_name`, `avatar_url`, `claimed_by_user_id` FK → users ON DELETE SET NULL, `claimed_at`, `follower_count_cached`, `follower_count_synced_at`. Unique(`platform`,`handle`), index(`claimed_by_user_id`).
   2. `create_source_posts_table` — per 02 §3.4: `platform`, `external_id varchar(255)`, `url varchar(2048)`, `influencer_id` FK → influencers SET NULL, `caption text`, `posted_at`, `privacy` default `'unknown'`, `oembed_json jsonb`, `fetch_status` default `'pending'`, `fetched_at`. **Unique(`platform`,`external_id`)**, index(`influencer_id`), index(`fetch_status`).
   3. `create_shares_table` — per 02 §3.5 **without** `published_place_source_id`: `user_id` FK → users CASCADE, `source_post_id` FK → source_posts CASCADE, `status` default `'pending'`, `failure_reason text`, `shared_via varchar(32)` (`share_sheet|paste_url|manual`), `published_at`. Unique(`user_id`,`source_post_id`), index(`status`), index(`source_post_id`).
   4. `create_media_assets_table` — per 02 §3.6: `source_post_id` FK CASCADE, `kind varchar(24)`, `storage_path varchar(2048)`, `disk varchar(32)` default `'s3'`, `mime`, `bytes bigint`, `duration_ms`, `width`, `height`, `sha256 char(64)`, `frame_at_ms`. Index(`source_post_id`,`kind`), unique(`sha256`,`source_post_id`), index(`sha256`).
   5. `create_analysis_runs_table` — per 02 §3.7: `share_id` FK CASCADE, `engine varchar(16)`, `model varchar(120)`, `status` default `'queued'`, `started_at`, `finished_at`, `input_tokens`, `output_tokens`, `cost_usd numeric(10,6)`, `overall_confidence numeric(4,3)`, `result_json jsonb`, `error text`. Index(`share_id`,`status`), index(`engine`,`model`), index(`finished_at`).
4. **CHECK constraints instead of native Postgres enums** (02 §2): after each `Schema::create`, add e.g. `DB::statement("ALTER TABLE shares ADD CONSTRAINT shares_status_check CHECK (status IN ('pending','fetching','analyzing','review','published','failed','rejected'))")`. Do this for every enum-bearing column (`platform`, `privacy`, `fetch_status`, `status`, `kind`, `engine`). All timestamps `timestamptz` (`$table->timestampTz(...)`); all PKs `$table->id()`.
5. **Eloquent models** in `apps/api/app/Models/`: `Influencer`, `SourcePost`, `Share`, `MediaAsset`, `AnalysisRun`. Enum casts (`'status' => ShareStatus::class`, `'oembed_json' => 'array'`, `'result_json' => 'array'`, decimal casts for `cost_usd`/`overall_confidence`). Relations: `Influencer hasMany SourcePost`, `Influencer belongsTo User (claimed_by_user_id)`; `SourcePost belongsTo Influencer / hasMany MediaAsset / hasMany Share`; `Share belongsTo User / belongsTo SourcePost / hasMany AnalysisRun`; `MediaAsset belongsTo SourcePost`; `AnalysisRun belongsTo Share`.
6. **Factories** for all five models (`database/factories/`), with useful states: `Share::factory()->review()/published()/failed()`, `MediaAsset::factory()->keyframe()/video()`, `AnalysisRun::factory()->succeeded()/failed()`, realistic `sha256` (`hash('sha256', fake()->uuid())`), valid enum values only.
7. **Pest model tests** (`apps/api/tests/Feature/Models/`): factories create rows; relations resolve both directions; enum casts round-trip; DB rejects a duplicate `(platform, external_id)` source_post (`QueryException`); DB rejects duplicate `(user_id, source_post_id)` share; CHECK constraint rejects an invalid `status` string via raw insert.

## Acceptance criteria

- [ ] All five tables exist with columns, types, nullability, defaults, indexes, and FK actions exactly per 02-data-model.md §3.3–§3.7.
- [ ] `shares` is created **without** `published_place_source_id` (added later per 02 §6 step 13).
- [ ] Seven PHP-backed string enums exist in `app/Enums` with values exactly per 02 §2; enum columns are `varchar` with CHECK constraints, not native Postgres enums.
- [ ] Unique constraint `source_posts(platform, external_id)` enforced at DB level and covered by a test.
- [ ] Unique constraint `shares(user_id, source_post_id)` enforced at DB level.
- [ ] All five models have relations wired and enum/jsonb casts; factories exist for all five and produce valid rows.
- [ ] Pest tests cover model creation, relations, casts, and constraint violations; `php artisan migrate:fresh` then `migrate:rollback` both succeed.
- [ ] `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` pass.

## Verification

```bash
cd apps/api
php artisan migrate:fresh --seed && php artisan migrate:rollback && php artisan migrate
php artisan test --filter=Models
vendor/bin/pint --test && vendor/bin/phpstan analyse
php artisan tinker --execute="
  \$s = \App\Models\Share::factory()->create();
  echo \$s->status->value, ' ', \$s->sourcePost->platform->value, ' ', get_class(\$s->user);
"
# expect: pending instagram App\Models\User (or similar valid enum values)
```

Tinker probe for the unique constraint: creating two shares with the same `user_id` + `source_post_id` must throw `Illuminate\Database\QueryException` (SQLSTATE 23505).

## Gotchas

- **timestamptz, not timestamp**: use `timestampTz()`/`timestampsTz()` everywhere; the spec mandates timezone-aware columns.
- **citext for `influencers.handle`** requires the `citext` extension — guard with `CREATE EXTENSION IF NOT EXISTS`. Laravel has no native citext column type; use `DB::statement` or `$table->addColumn('citext', 'handle')` after registering a grammar macro — simplest is raw statement after create.
- `numeric(4,3)` for confidence: use `$table->decimal('overall_confidence', 4, 3)->nullable()`. Eloquent `decimal:3` cast returns strings — fine, don't float-cast.
- `sha256` is `char(64)`: `$table->char('sha256', 64)`.
- Rollback must drop CHECK constraints implicitly via `dropIfExists` — fine, but test `migrate:rollback` actually runs (raw `ALTER TABLE` statements need no explicit down step beyond dropping the table).
- Don't add `transcript_json` to `source_posts` here — T-018 adds it in its own migration.
- SQLite will not work for these tests (citext, jsonb, CHECK, PostGIS): the Pest suite must run against the Postgres service (as configured in T-006 CI).

## Log
- **2026-07-09** — Done. **PR #6** (`feat/t011-core-migrations` → `feat/t009-media-storage`, stacked). All gates green: `composer test` 63 passing / 165 assertions (13 model tests), Pint (95 files), PHPStan L6. `migrate:fresh`/`rollback`/`migrate` all succeed.
- **Implementation notes**:
  - 7 enums, 5 migrations per §3.3–3.7. Enum CHECKs built from enum cases via `App\Support\Database\Constraints::enumCheck($table,$col,EnumClass::class)` (kept DB + PHP in lockstep). `shares` created **without** `published_place_source_id` (T-023).
  - **Rollback fix (cross-task)**: the T-003 extensions migration `down()` dropped postgis, which fails because the `postgis/postgis` image auto-installs `postgis_topology`/`postgis_tiger_geocoder` that depend on it. Changed `down()` to a no-op (dropping shared DB extensions on rollback is wrong regardless). Carried on this branch.
  - **Mass assignment (review, both agents)**: switched models from `$guarded=['id']` to explicit `$fillable` allow-lists matching the `User` precedent — system fields (Share.status/user_id, Influencer.claimed_by_user_id, AnalysisRun.result_json/cost_usd) are not mass-assignable. Factories still set them because Eloquent factories create inside `Model::unguarded()`. Added a mass-assignment test.
  - Added integrity CHECKs (analysis_runs confidence ∈ [0,1], cost ≥ 0) and tests for citext handle collision + `(sha256, source_post_id)` uniqueness (per /simplify + /security-review).
  - jsonb doesn't preserve key order → assert jsonb round-trips by key, not array identity.
