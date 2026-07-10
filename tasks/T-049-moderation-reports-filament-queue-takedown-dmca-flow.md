# T-049 — Moderation: reports, Filament queue, takedown/DMCA flow

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-030, T-008
- **Target paths:** `apps/api/app/Http/Controllers/ReportController.php`, `apps/api/app/Filament/Resources/`
- **Spec refs:** [02-data-model.md#reports](../02-data-model.md#317-reports), [07-risks-decisions.md#risk-register](../07-risks-decisions.md#1-risk-register)

## Context

Reelmap is a UGC app: Apple Guideline 1.2 and Google's UGC policy require report/moderation tooling before store submission (R-02), and storing third-party media/influencer profiles requires a takedown path (R-07, ADR-010, IR-2, FR-52/53/57). At this point the Filament admin exists (T-008) and places/place_sources/shares are live (T-030). This task ships the `reports` table + `POST /api/v1/reports`, the Filament moderation queue with audit-logged hide/remove/ban actions, and the takedown/DMCA workflow. T-054 (store readiness) depends on it. App code lives in the separate app repo created by T-001.

## Implementation steps

1. **Migration + model — `reports`** exactly per `02-data-model.md §3.17`: `reporter_user_id` (FK users, cascade), polymorphic `reportable_type`/`reportable_id` (morph aliases: `place`, `share`, `user`, `source_post` — plus `offer` per API spec §2.16), `reason` (`ReportReason` PHP-backed enum: `spam`, `wrong_place`, `inappropriate`, `copyright`, `fraud`, `other`), `details` text nullable, `status` (`ReportStatus` enum: `open`, `reviewing`, `resolved`, `dismissed`, default `open`), `resolved_by_user_id` (FK users, SET NULL), `resolved_at`. Indexes: `(reportable_type, reportable_id)`, `(status)`, unique `(reporter_user_id, reportable_type, reportable_id, reason)`. Add `Report` model, factory, enum casts, morph map entries.
2. **`POST /api/v1/reports`** in `App\Http\Controllers\Api\V1\ReportController` (route in `apps/api/routes/api.php`, auth:sanctum): validates `{reportable_type, reportable_id, reason, details?}` against the morph map and enum; returns `201 {data: report}` in the standard envelope; duplicate report (unique constraint) returns `409 conflict` per the error shape in `03-api-design.md §1`. Add the JSON Schema for the report resource in `packages/contracts/schemas` and regenerate TS types (same-PR rule, `03-api-design.md §5`).
3. **Moderation state columns**: add `hidden_at` (timestamptz nullable) + `hidden_reason` to `places` and `shares` if not already present; all public queries (places index/show, map, feed, search index) must exclude hidden rows — implement as a global scope `VisibleScope` and update the Scout `shouldBeSearchable()`.
4. **Filament moderation queue** (`apps/api/app/Filament/Resources/ReportResource.php`): table filtered by `status`, grouped/badge-counted by reason; record page renders the reportable inline (share caption + thumbnail, place card, user profile) with the reporter and prior reports against the same target. Actions (each a Filament Action with confirmation modal + required note): **Hide content** (sets `hidden_at`), **Remove content** (unpublish share / deactivate place source), **Ban user** (reuses the T-008 ban mechanism, revokes Sanctum tokens), **Dismiss**, **Resolve**. Resolving sets `resolved_by_user_id` + `resolved_at`.
5. **Audit trail**: install `spatie/laravel-activitylog` (resolve latest stable) and log every moderation action (causer = admin, subject = reportable, properties = action, note, report_id). Surface the activity log as a relation manager on `ReportResource` and on the Users resource. Ledger-affecting actions are out of scope here (see T-050 gotchas — never hard-delete financial history).
6. **Takedown/DMCA flow** (IR-2, R-07): a `TakedownRequest` Filament resource (simple table: requester name/email, role `rightsholder|influencer`, target URL or source_post id, notes, status `received|actioned|counter_notice|closed`). Actioning a takedown runs a `ProcessTakedown` action/job that: unpublishes every affected share (`status` → `rejected`, `failure_reason: takedown`), deletes the `place_sources` row(s) for that source_post, deletes associated `media_assets` from R2 (keyframes/thumbnails included), and annotates the place ("source removed" — the place itself survives per FR-30). Intake channel: a public static form/email (`dmca@reelmap.app`) documented in `docs/`; requests are entered manually into Filament by ops — no public API.
7. **Mobile report entry points** (`apps/mobile`): "Report" action in the overflow menu on Place detail (`/places/[id]`), on share cards (feed + place source cards), and on public profiles (`/users/[handle]`) → bottom sheet with `ReportReason` options + optional details → `POST /reports` → toast confirmation. Duplicate 409 shows "Already reported — thanks." Use a typed hook in `src/api/hooks/`.
8. **Tests (Pest)**: report creation happy path + validation shape + duplicate 409 + rate-limit headers; hidden content excluded from places/map/feed endpoints; takedown action unpublishes share, removes place_source, deletes media assets (fake disk), place survives; ban revokes tokens; every moderation action writes an activity row. Mobile: component test for the report sheet.

## Acceptance criteria

- [ ] `POST /api/v1/reports` accepts `{reportable_type: place|share|user|source_post|offer, reportable_id, reason, details?}` with `reason` restricted to the `ReportReason` enum, returns 201 envelope, 422 on bad enum, 409 on duplicate.
- [ ] `reports` table matches `02-data-model.md §3.17` including the unique `(reporter_user_id, reportable_type, reportable_id, reason)` constraint.
- [ ] Filament moderation queue lists open reports with the reported content rendered inline, and supports hide / remove / ban / dismiss / resolve.
- [ ] Every moderation action is audit-logged with acting admin, action, note, and timestamp, and the log is visible in Filament.
- [ ] Hidden/removed content disappears from all public API surfaces (places, map, feed, search) — verified by tests.
- [ ] Takedown flow: a rightsholder/influencer request actioned in Filament unpublishes the share(s), removes the `place_sources` link, and deletes stored media assets; the place entry survives with the source labeled unavailable (FR-30).
- [ ] Mobile app exposes report entry points on place detail, share cards, and public profiles, wired to `POST /reports`.
- [ ] Report resource schema added to `packages/contracts/schemas` with regenerated TS types committed.

## Verification

```bash
cd apps/api
php artisan migrate:fresh --seed
php artisan test --filter=Report        # report API + queue tests green
php artisan test --filter=Takedown     # takedown flow tests green
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

Manual: log in to `/admin` as an admin → Moderation → Reports: open a seeded report, hide the share, confirm it vanishes from `GET /api/v1/feed` and `GET /api/v1/map/places` (curl with a user token); check the activity log entry appears. Create a TakedownRequest for a seeded source_post, action it, then `GET /api/v1/places/{slug}` — place still returns 200 but the source card is gone/marked unavailable, and the R2 (fake/local) objects are deleted. On the mobile dev client: place detail → overflow → Report → submit → toast; submit again → "Already reported."

## Gotchas

- **Apple 1.2 rejection risk**: reviewers look for a *visible* report mechanism on UGC and evidence of timely moderation. Don't bury report behind login-only long-press; make it an obvious menu item. T-054 will also need user **blocking** — keep the polymorphic design so a `blocks` table can reuse patterns.
- API spec §2.16 says reportable includes `offer`, data model §3.17 says `source_post` — support the union; morph map must be explicit strings, never FQCNs (DB portability).
- Never hard-delete users/shares tied to `ledger_entries` or `redemptions` from a ban/remove action — hide and anonymize instead (financial history is append-only, ADR-009; full policy in T-050).
- Takedown deletes media but must not cascade-delete `source_posts` (other analytics reference it); null out media, keep the row with `fetch_status` unchanged and an annotation.
- Meilisearch index won't auto-drop hidden records unless `shouldBeSearchable()` is updated **and** the model is re-saved — flush/re-sync in the moderation action.
- No `/api/v1/admin/*` routes — all admin ops stay in Filament (`03-api-design.md §2.16` is binding).
