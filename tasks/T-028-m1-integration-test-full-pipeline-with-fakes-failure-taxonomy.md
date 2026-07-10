# T-028 — M1 integration test: full pipeline with fakes + failure taxonomy

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-024, T-013, T-017, T-018
- **Target paths:** `apps/api/tests/Feature/Pipeline/`
- **Spec refs:** [04-analysis-pipeline.md](../04-analysis-pipeline.md), [ROADMAP.md#m1](../ROADMAP.md#m1--ingest--analyze-the-core-loop)

## Context

Every M1 pipeline piece now exists: adapters (T-013/T-014), media prep (T-017), transcription (T-018), ModelRouter + engines (T-019/T-020), extraction (T-021), resolve (T-023), review/publish (T-024). This task proves they compose: end-to-end Pest feature tests that drive a share `pending → published` entirely on fakes, exercise the Ollama→OpenRouter fallback, and pin the failure taxonomy — the ROADMAP M1 exit criteria, runnable in CI with zero network. App code lives in the separate app repo created by T-001, NOT this plans folder.

## Implementation steps

1. **Test harness** in `apps/api/tests/Feature/Pipeline/` with a shared `PipelineTestCase` (or Pest `uses()` setup) that:
   - Uses the Postgres+PostGIS test database (per T-006 CI service; `migrate:fresh`).
   - Runs queues synchronously (`QUEUE_CONNECTION=sync`) so `Bus::chain` executes inline in-order — the chain itself is the unit under test, so do **not** `Bus::fake()` in the happy-path tests.
   - Registers a **fake `SourceAdapter`** in the `AdapterRegistry` (`supports()` a `https://fake.test/post/{id}` URL scheme; `fetchMetadata` returns a fixture `SourcePostData` with caption/author; `fetchMedia` points at a bundled tiny fixture video). Reuse fixtures from T-013/T-017 where possible.
   - Fakes ffmpeg-dependent stages if ffmpeg isn't guaranteed in CI: either require ffmpeg (preferred, matches T-017's CI setup — reuse its small fixture video) or bind fake `Media` services producing canned keyframes/audio assets.
   - Binds a fake `Transcriber` (canned transcript), `FakeGeocoder` (seeded results), and fakes both AI engines via `Http::fake()`: Ollama `/api/tags` + `/api/chat`, OpenRouter `/chat/completions` — responses are golden-file extraction payloads valid against `packages/contracts/extraction.schema.json`. Enable `Http::preventStrayRequests()` globally for the suite.
   - `Notification::fake()` where push side-effects aren't the assertion target.
2. **Happy path test** (M1 exit criterion 1): authed user `POST /api/v1/shares` with the fake URL → assert final `shares.status = published`, `source_posts` + `media_assets` (video/audio/keyframes/thumbnail) + succeeded `analysis_runs` + `place_sources` rows exist, `shares.published_place_source_id` set, and the `places` row has a real geo point: `SELECT ST_X(location::geometry), ST_Y(location::geometry)` matches the FakeGeocoder seed. Assert `GET /shares/{id}` reflects the terminal status + place candidate shape.
3. **Fallback test** (M1 exit criterion 3): `Http::fake()` Ollama `/api/tags` unreachable (connection exception) or `/api/chat` erroring, OpenRouter succeeding → assert **two** `analysis_runs` rows: `engine: local, status: failed` (with fallback reason) and `engine: openrouter, status: succeeded` with `cost_usd > 0`; share still reaches `published`. Variant: local returns `confidence.overall < 0.5` → escalation run recorded.
4. **Review-gate test** (M1 exit criterion 4): fake model returns `overall = 0.6` → share parks at `review`; then `PATCH /shares/{id}` with corrections + `action: publish` → `share_corrections` rows, share `published`, snapshot = corrected payload.
5. **Failure taxonomy matrix** (one test per stage, table-driven where practical) asserting `shares.status = failed` + the exact `failure_code` per 04 §8:
   | broken stage (how) | expected `failure_code` |
   |---|---|
   | adapter throws `PostUnavailable`, manual fallback declined/absent | `fetch_unavailable` |
   | adapter requires auth, none linked | `fetch_auth_required` |
   | media exceeds 500 MB / 15 min cap | `media_too_large` |
   | ffmpeg process fails | `ffmpeg_error` |
   | transcriber throws (non-silent case) | `transcribe_error` |
   | both engines produce invalid JSON through repair loops | `invalid_model_output` |
   | estimated cost over cap, no cheaper model | `cost_cap_exceeded` |
   | geocode null + user path exhausted / resolve hard failure | `geocode_failed` / `resolve_conflict` |
   | user discards from review | `user_discarded` |
   Also assert the review-not-failed cases route correctly: silent audio → empty transcript (not failure); `place.name: null` → `review`/`no_place_extracted`; ambiguous candidates → `review`/`ambiguous_place`; quota exhausted → `review`/`quota_exhausted`.
6. **CI**: ensure the suite runs in the existing `api` GitHub Actions job (Postgres+PostGIS service, ffmpeg installed per T-017). Keep total runtime reasonable (< ~2 min): tiny fixture video, shared setup, no sleeps.

## Acceptance criteria

- [ ] End-to-end feature test drives a share `pending → published` through the real job chain with fake adapter + fake models, producing a `places` row with a correct PostGIS point and full provenance rows (`source_posts`, `media_assets`, `analysis_runs`, `place_sources`).
- [ ] Fallback test proves a failing local engine yields two `analysis_runs` rows (failed local + succeeded openrouter with cost) and a published share.
- [ ] Low-confidence extraction routes to `review` and the PATCH-confirm path publishes with corrections recorded.
- [ ] Every pipeline stage failure produces `status = failed` with the correct `failure_code` from the 04 §8 taxonomy (matrix test), and review-reasons (`no_place_extracted`, `ambiguous_place`, `geocode_failed`, `quota_exhausted`) are distinguished from failures.
- [ ] The whole suite passes in CI with `Http::preventStrayRequests()` — zero live network calls.

## Verification

```bash
cd apps/api
php artisan test tests/Feature/Pipeline
php artisan test            # full suite still green
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Expected: all Pipeline tests green locally and in the GitHub Actions `api` job (check the workflow run: no stray-request exceptions, runtime bounded). The ROADMAP M1 exit criteria 1, 3, 4 are now demonstrated by tests (criterion 2, the real-device Instagram smoke, remains manual).

## Gotchas

- `QUEUE_CONNECTION=sync` executes chains depth-first inline — a `failed()` hook behaves slightly differently than on Horizon (sync throws propagate to the dispatch call). Assert on persisted share state, not on exceptions, and where a test needs retry semantics, call the job's `failed()` explicitly rather than simulating Horizon.
- Fake model responses must be **valid against the schema including `additionalProperties: false` and all required nullable keys** — hand-written fixtures that omit `null` fields will send tests down the repair loop and mask real behavior. Generate fixtures by validating them in the test bootstrap.
- The repair loop makes multiple HTTP calls per engine attempt — `Http::fake()` sequences (`Http::fakeSequence()` / callback fakes keyed by request body) must supply enough responses or the invalid-output tests will consume the queue and pass vacuously.
- PostGIS assertions need the geometry cast (`ST_X(location::geometry)`) — reading a geography column raw returns EWKB hex.
- Time-sensitive pieces (daily quota, cache TTLs) need `Cache::flush()`/`travel()` between tests or the quota test poisons its neighbors — the Redis spend counter persists across tests on the same fake store.
- Don't over-fake: the point of this suite is composition. Only fake at the external boundaries (adapter HTTP, AI HTTP, geocoder, push) — jobs, state machine, resolver, and validators must be the real classes.
