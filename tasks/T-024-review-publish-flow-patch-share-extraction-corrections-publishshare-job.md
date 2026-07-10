# T-024 — Review + publish flow: PATCH share extraction corrections, PublishShare job

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-023, T-016
- **Target paths:** `apps/api/app/Http/Controllers/ShareController.php`, `apps/api/app/Jobs/PublishShare.php`
- **Spec refs:** [03-api-design.md#shares](../03-api-design.md#24-shares-ingest), [04-analysis-pipeline.md#user-review-step](../04-analysis-pipeline.md#7-user-review-step)

## Context

The pipeline can now take a share all the way to a resolved place (T-023) or park it in `review`, and the Shares API + state machine exist from T-016. This task closes the loop: the endpoint where the user confirms/corrects the extraction, the correction storage that becomes ground truth for model evals, and the terminal `PublishShare` job that makes the map entry live. It completes the M1 core loop and unblocks T-026 (ExtractionReview screen) and T-028 (integration test). App code lives in the separate app repo created by T-001, NOT this plans folder.

## Implementation steps

1. **`PATCH /api/v1/shares/{id}`** on `ShareController` (owner-only via policy; note the mobile spec's `POST /shares/:id/confirm` is this same operation — implement the API-spec PATCH):
   - Only valid while `status = review` (invalid status → 409 `conflict` via the T-016 state machine).
   - Request body: corrected `extraction` fields (full or partial extraction object), optional `place_candidate.google_place_id` override (from the dedupe/ambiguous picker) or manual `{lat, lng}` pin, plus `{"action": "publish"}` to confirm. Without `action: publish`, corrections are saved but the share stays in `review`.
   - Merge corrections over the winning run's `result_json` and validate the merged payload against `packages/contracts/extraction.schema.json` (T-005 helper) → 422 `validation_failed` with per-field details on failure.
   - Never mutate `analysis_runs.result_json`. Store the corrected payload separately on the share (e.g. `shares.corrected_extraction_json`, add via migration) — the "corrected payload is stored separately on the share" rule from 04 §7.
2. **`share_corrections` table + model** (migration): `share_id` FK, `field_path` (dotted, e.g. `place.name`), `model_value jsonb`, `user_value jsonb`, timestamps. On PATCH, diff corrected vs original per dotted field path and insert one row per changed field. These are the ground-truth corpus (join against `analysis_runs.model` for accuracy dashboards).
3. **Confirm dispatch**: on `action: publish`, transition per state machine and re-dispatch the chain **from `ResolvePlace`** with the corrected payload (a `google_place_id` override short-circuits the dedup tree to that place; a manual pin feeds the geocode-failed retry path). Ambiguous-candidate selection (`place_candidate` pointing at an existing candidate place) attaches to that place instead of creating a duplicate.
4. **`DELETE /shares/{id}` discard path** (if not finished in T-016): share → `failed` with `failure_code: user_discarded` when discarded from review.
5. **`PublishShare` job** (`queue: publish`, tries 3, backoff `[5, 30, 120]`, timeout 30s), terminal stage:
   - Stage-contract guards + idempotency (already `published` → no-op).
   - Persist the `place_sources` snapshot: ensure the row from ResolvePlace carries `extraction_snapshot_json` = the payload **as published** (corrected if corrections exist, else original) — the immutable publish-time snapshot per 02 §3.9; corrections thereby live alongside the original on the place_source snapshot.
   - Set `shares.status = published`, `published_at`, `shares.published_place_source_id`; mark first-publisher credit (`place_sources.is_primary` for the place's first source).
   - Activate the place: first source keeps `places.status = pending` (unverified) unless the user explicitly confirmed in review; a second independent source **or** a user confirmation flips `pending → active` (04 §6 point 4).
   - Update counters: `places.shares_count`, `avg_extraction_confidence` rolling average.
   - Fire `ShareStatusChanged` (push notification `share.published` handled by T-027; event exists from T-016).
6. **Feature tests** (Pest, fakes from T-021/T-022/T-023):
   - **Auto-publish path**: high-confidence extraction (`overall ≥ 0.75`, unambiguous resolve) runs Extract → Resolve → Publish with no review stop; share `published`, place created, counters set.
   - **Review path**: low-confidence share in `review` → PATCH with corrections + `action: publish` → `share_corrections` rows created, original `result_json` untouched, snapshot on `place_sources` equals corrected payload, share `published`.
   - PATCH validation failure → 422 with field details; PATCH on non-review share → 409; non-owner → 403; candidate-selection PATCH attaches to the chosen existing place.

## Acceptance criteria

- [ ] `PATCH /api/v1/shares/{id}` accepts corrected extraction (validated against `extraction.schema.json`), optional place-candidate/pin override, and `action: publish`; guarded to `status = review` and the share owner.
- [ ] Corrections are persisted as `share_corrections` rows (`field_path`, `model_value`, `user_value`) and the corrected payload is stored separately — the original `analysis_runs.result_json` is never overwritten; the publish-time payload is frozen on `place_sources.extraction_snapshot_json`.
- [ ] `PublishShare` sets share → `published` (+`published_at`, `published_place_source_id`), activates the place per the second-source/user-confirmation rule, and updates `shares_count` + `avg_extraction_confidence`.
- [ ] Confirm re-dispatches from `ResolvePlace` with the corrected payload; selecting a dedupe candidate attaches to that existing place.
- [ ] Feature tests cover the auto-publish (high-confidence) path and the review path end-to-end, plus authz, invalid-status, and validation-error cases.

## Verification

```bash
cd apps/api
php artisan test --filter=PublishShare
php artisan test --filter=ShareReview   # or the PATCH feature test group
php artisan test tests/Feature/Shares
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Expected: green. Manual smoke: seed a share into `review` (factory), PATCH via `php artisan tinker`/HTTP client with a corrected name + `action: publish`, confirm `shares.status = published` and a `places` row with a geography point exists.

## Gotchas

- Endpoint-name drift: 05-mobile-app says `POST /shares/:id/confirm`; 03-api-design (canonical) and this task say `PATCH /shares/{id}` with `action: "publish"`. Implement PATCH; T-026 must call PATCH.
- The corrected payload must re-validate against the **full** schema (`additionalProperties: false`) — a partial PATCH body merged naively can drop required keys. Merge onto the original, then validate the whole object.
- Re-dispatching from `ResolvePlace` must pass the corrected payload, not re-read the (uncorrected) winning run — thread it through the job constructor or read `shares.corrected_extraction_json ?? run.result_json` inside ResolvePlace/Publish consistently.
- Idempotency across retries: `PublishShare` may run twice (Horizon redelivery). Counter updates must be guarded by the status check (only increment on the actual `review/analyzing → published` transition), and `is_primary` uses the partial unique index — catch the conflict, don't pre-check.
- Place activation is subtle: auto-published first-source places stay `pending` (flagged unverified but on the map); user-confirmed review publishes may activate immediately. Encode this as one small, tested method — M2's dedup queue depends on `pending` semantics.
- `share_corrections` values should be `jsonb` (not text) so array/object fields (dishes, tags) diff cleanly.
