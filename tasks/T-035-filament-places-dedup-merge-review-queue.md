# T-035 — Filament: places dedup/merge review queue

- **Phase:** M2 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-023, T-008
- **Target paths:** `apps/api/app/Filament/Resources/`
- **Spec refs:** [../02-data-model.md#dedup](../02-data-model.md) (§4), [../ROADMAP.md#m2](../ROADMAP.md)

## Context

T-023's `ResolvePlace` decision tree parks ambiguous matches (name similarity 0.40–0.65 within 75 m) as `status = pending` places awaiting a human decision, and T-008 stands up the admin-gated Filament panel at `/admin`. This task builds that review queue: pending/ambiguous places listed with candidate duplicates side-by-side, a merge action implementing the §4.3 merge rules, an undo path (M2 exit criterion: "a wrong merge can be undone in Filament"), plus browsable Shares and AnalysisRuns resources for pipeline debugging. App code lives in the separate app repo created by T-001; use the latest Filament version pinned by T-008.

## Implementation steps

1. **PlaceResource** (`app/Filament/Resources/PlaceResource.php`, latest-Filament conventions with `Pages/` + `RelationManagers/`): table with name, city, status badge (`PlaceStatus` colors: pending=warning, active=success, merged=gray, hidden=danger), `shares_count`, `avg_extraction_confidence`, google_place_id presence icon; filters on status/country/city; default table filter preset **"Review queue"** = `status = pending`. Detail/view page shows all fields + a small static map (embed via OSM/Google static image or leaflet widget — read-only).
2. **Candidate duplicates panel.** On the pending place's view page, render nearby candidates side-by-side using the same query as the resolver (keep it in `App\Services\Places\PlaceResolver::candidatesFor(Place $place)` so admin UI and pipeline share one implementation):

```sql
SELECT id, name, similarity(normalized_name, :name) AS score,
       ST_Distance(location, :point::geography) AS meters
FROM places
WHERE id <> :id AND status IN ('pending','active')
  AND ST_DWithin(location, :point::geography, 75)
ORDER BY score DESC
```

   Show per candidate: name, score, distance, address, source counts, their source-post thumbnails — enough context to decide "same restaurant?".
3. **Actions on a pending place:**
   - **Approve as new** → `status = active` (Scout picks it up; save via model so search sync fires).
   - **Merge into candidate…** → modal selecting the target (defaults to best-scoring candidate), then execute the §4.3 merge in one DB transaction:
     1. Resolve target to its terminal place if itself merged (single hop — no chains).
     2. Rehome `place_sources`: `UPDATE ... SET place_id = A WHERE place_id = B`; on `unique(place_id, share_id)` conflict keep A's row and delete B's duplicate.
     3. B: `status = 'merged'`, `merged_into_place_id = A` (row retained forever).
     4. Recompute A's counters (`shares_count`, `avg_extraction_confidence`); union tags keeping max pivot confidence per tag; keep A's core fields, backfill A's nulls from B (address, phone, website, hours, google_place_id if A lacks one).
     5. Rehome `offers`, `place_claims`, `reports` from B to A (no-ops until M4/M3 tables exist — schema-guard).
   - **Hide** → `status = hidden` (spam/not-a-restaurant).
4. **Merge audit + undo.** Before mutating, snapshot everything needed to reverse into a `place_merges` table (new migration): `id, source_place_id (B), target_place_id (A), performed_by_user_id, rehomed_place_source_ids jsonb, dropped_duplicate_place_sources jsonb (full rows), b_snapshot jsonb (B's pre-merge attributes + tag pivots), a_backfilled_fields jsonb, undone_at nullable, timestamps`. **Unmerge action** (on the merged place or the merge log resource, only while `undone_at` null and B hasn't been merged again): transactionally move the recorded place_source ids back to B, re-insert dropped duplicates, restore B's status/attributes/tags from snapshot, null A's backfilled fields, recompute both counters, mark `undone_at`. Both merge and unmerge sync Scout for A and B (B de-indexes when `merged`, re-indexes on undo).
5. **ShareResource + AnalysisRunResource (read-only debugging).** Shares: table with user, source-post URL, status badge, `failure_reason`, timestamps; filters by status/platform; view page shows status history and links to its analysis runs. AnalysisRuns: engine/model/status/confidence/cost/tokens/timings, pretty-printed `result_json`, error text; filter by engine + status. Disable create/edit/delete on both (`canCreate(): false` etc.).
6. **Authorization.** All three resources gated to `is_admin` (Filament panel guard from T-008); merge/unmerge actions additionally logged with the acting admin (`performed_by_user_id`).
7. **Tests.** Pest feature tests hitting the service layer (Filament actions delegate to `PlaceMerger` service — test that directly, plus one Livewire test that the action is wired): merge rehomes sources and drops the duplicate correctly; counters + tag union (max confidence) recomputed; null-backfill; merging into an already-merged target follows to terminal; unmerge restores B exactly (attribute + pivot assertions) and A's counters; unmerge blocked after `undone_at`; hidden/active transitions; non-admin gets 403 on `/admin`.

## Acceptance criteria

- [ ] Filament PlaceResource lists places with a "Review queue" filter showing `status = pending` places
- [ ] Pending place view shows candidate duplicates side-by-side with trigram similarity score and `ST_DWithin`/distance, sharing the resolver's candidate query
- [ ] Merge action implements 02-data-model §4.3 exactly: rehome place_sources (conflict-safe), B → `status=merged` + `merged_into_place_id`, counters recomputed, tags unioned at max confidence, A's nulls backfilled, single-hop terminal resolution, all in one transaction
- [ ] Every merge writes a `place_merges` audit row with snapshots and acting admin
- [ ] Unmerge/undo restores the pre-merge state (sources, duplicates, B's attributes/tags/status, A's counters) and is blocked once already undone or B re-merged
- [ ] Approve-as-new and Hide actions transition status correctly and sync the search index (merged/hidden places drop out of Meilisearch when T-031 is live)
- [ ] Shares and analysis_runs are browsable read-only in Filament (status, failure_reason, result_json, cost/tokens) with filters
- [ ] All resources restricted to `is_admin`; merge/unmerge covered by service-level Pest tests + a Livewire wiring test
- [ ] Two shares of the same restaurant via different posts end up on one place after a merge (M2 exit criterion scenario covered by a test)

## Verification

```bash
cd apps/api
php artisan migrate && php artisan test --filter="PlaceMerge|Filament"
php artisan serve
```

Manual: log in at `http://localhost:8000/admin` as an admin (T-008 seeder) → Places → "Review queue" filter shows pending places seeded by two near-identical fixtures ("Lanzhou Noodle House" / "Lanzhou Noodles", 40 m apart) → open one → candidate panel shows the other with score ≈ 0.7 and ~40 m → Merge into candidate → target place now lists both source posts, counters updated, merged place shows gray "merged" badge and redirect note → open the merge log → Unmerge → both places restored with original sources. Non-admin user → `/admin` denied. Shares list shows a failed share's `failure_reason`; its analysis run shows `result_json`.

## Gotchas

- **Undoing a wrong merge is only possible with snapshots:** the conflict-handling step *deletes* duplicate place_source rows and backfills A's fields — without persisting dropped rows + B's pre-merge attributes in `place_merges`, unmerge silently loses evidence. Snapshot first, mutate second, same transaction.
- **Merge chains:** merging C into B after B was merged into A creates a chain unless you resolve to the terminal place first; likewise block unmerge of A→B if B has since been merged elsewhere.
- **Counter drift:** recompute `shares_count`/`avg_extraction_confidence` from `place_sources` aggregates, don't increment — merges, unmerges and duplicate drops make incremental math wrong.
- **Scout sync on bulk updates:** `DB::table(...)->update()` bypasses Eloquent events, so Meilisearch keeps stale docs; rehome with query builder for speed but explicitly `searchable()`/`unsearchable()` A and B afterwards (see T-031 gotcha).
- **published_place_source_id on shares:** rehoming changes the source's `place_id`, not the share FK — nothing to touch on `shares`, but assert in tests that share → published place resolution (via place_source → place, following merges) still lands on A.
- **Filament version drift:** T-008 pins the Filament major; follow its resource generator output (schema/table class layout changed across majors) rather than copying older-version snippets.
