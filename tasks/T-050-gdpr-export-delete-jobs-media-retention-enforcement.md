# T-050 — GDPR: export/delete jobs + media retention enforcement

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-024, T-015
- **Target paths:** `apps/api/app/Jobs/Gdpr/`, `apps/api/app/Console/Commands/`
- **Spec refs:** [07-risks-decisions.md](../07-risks-decisions.md), [00-product-spec.md#nfr](../00-product-spec.md#4-non-functional-requirements)

## Context

R-06 (GDPR) and R-07/ADR-010 (copyright — analyze-then-delete) are launch blockers: users must be able to export and delete their data (NFR-10), and downloaded original videos may live at most 72 h before hard deletion. The publish flow (T-024) and `platform_accounts` with encrypted tokens (T-015) exist, so all data surfaces to purge/export are in place. This unblocks T-054 (store readiness — Apple requires in-app account deletion, Guideline 5.1.1(v)). App code lives in the separate app repo created by T-001; the mobile privacy screen (`/settings/privacy`) shell exists from T-039.

## Implementation steps

1. **Endpoints** (routes exist per `03-api-design.md §2.2` and `05-mobile-app.md` screen #16): `DELETE /api/v1/me` — soft-deletes the user (sets `users.deleted_at`), revokes all Sanctum tokens immediately, and dispatches `App\Jobs\Gdpr\PurgeUserData` (delayed grace period, e.g. 14 days, configurable `GDPR_PURGE_GRACE_DAYS`; re-login within grace cancels the purge). `POST /api/v1/me/export` — dispatches `App\Jobs\Gdpr\ExportUserData`, returns 202.
2. **`PurgeUserData` job** (queue `notifications` or a new `housekeeping` queue; idempotent):
   - Hard-delete: `platform_accounts` (encrypted tokens gone), `devices`, `personal_access_tokens`, `notifications`, `follows` (both directions), `reports` filed by the user, avatar file on storage.
   - Shares/attribution: keep published `shares` + `place_sources` rows (community data) but **anonymize** — repoint sharer attribution to a system "Deleted user" record or null `user_id` where the FK allows; unpublished/failed shares hard-deleted with their `analysis_runs` and user-uploaded `media_assets` (manual screen recordings).
   - Financial: **never delete** `ledger_entries`, `redemptions`, `payouts` — scrub PII on the user row (name → `Deleted user`, unique-randomized `username`/`email`, null `bio`/`avatar_path`/`stripe_connect_account_id` after confirming no pending payout) and keep the soft-deleted row as the FK anchor.
   - Influencer link: if the user had claimed an `influencers` row, unclaim it (`claimed_by_user_id` → null, `is_influencer` false); the influencer identity itself is public business data (R-06 lawful-basis note) with the T-049 takedown path.
   - Write an `activity_log` entry recording purge completion (no PII in the log).
3. **`ExportUserData` job**: collects profile, platform_accounts metadata (handles/scopes — never tokens), shares + extraction snapshots, places created via their shares, follows, notifications, ledger/redemption history, devices — as one JSON file per entity (machine-readable, NFR-10) zipped to the private `media` disk; generates a signed URL (24 h expiry) and delivers it via email/notification. Reuse API Resources so shapes match `packages/contracts`.
4. **Media retention command** (ADR-010): `App\Console\Commands\PruneOriginalMedia` (`php artisan media:prune-originals`) deletes `media_assets` rows with `kind = video|audio` (originals + extracted WAV) older than `MEDIA_ORIGINAL_RETENTION_HOURS` (default 72) whose share chain has finished (status `published|failed|rejected`) — deleting both the R2 object and the DB row. Keyframes and thumbnails are **kept**. Schedule hourly in `routes/console.php` / scheduler. Also implement NFR-11: prune `source_posts.oembed_json` raw payloads older than 90 days except assets backing live places.
5. **Retention safety rails**: skip assets whose share is still mid-pipeline (`pending|fetching|analyzing|review`) even past 72 h only if the share is younger than the retention window + a hard ceiling (e.g. 7 days) — after the ceiling, delete anyway and let retry re-fetch (ADR-010: re-analysis requires re-fetch). Log every deletion with `share_id` correlation.
6. **Encryption audit**: a Pest architecture/feature test asserting `PlatformAccount` casts `access_token`/`refresh_token` with `encrypted`, that raw DB values are not plaintext (query the column directly and assert it doesn't contain the seeded token), and that no API Resource ever serializes token fields (NFR-9).
7. **Mobile** (`apps/mobile/app/settings/privacy.tsx`): wire the existing screen — Export button → `POST /me/export` → "We'll email you a download link"; Delete account → typed-confirmation ("DELETE") → `DELETE /me` → clear SecureStore + session, route to welcome.
8. **Tests (Pest)**: full purge feature test (create user with tokens, devices, shares, ledger rows → run purge → assert deletions/anonymizations table-by-table, ledger untouched and still balanced); export archive contains expected files; retention command deletes only eligible originals, keeps keyframes/thumbnails, is idempotent; grace-period cancel works.

## Acceptance criteria

- [ ] `DELETE /api/v1/me` soft-deletes immediately, revokes all tokens, and a queued purge hard-deletes/anonymizes after the grace period — verified by a feature test asserting per-table outcomes.
- [ ] Purge removes `platform_accounts` (tokens), `devices`, `personal_access_tokens`, unpublished shares + their media/analysis_runs; published attributions are anonymized, not orphaned.
- [ ] `ledger_entries`/`redemptions`/`payouts` survive purge with PII scrubbed on the user row; ledger invariant (sum debits = sum credits) still holds post-purge.
- [ ] `POST /api/v1/me/export` produces a downloadable machine-readable archive (signed URL, expiring) covering all user-owned data.
- [ ] `media:prune-originals` deletes original video/audio assets older than 72 h post-analysis (storage object + row) while keeping keyframes, thumbnails, transcript text, and extraction JSON; scheduled hourly.
- [ ] Raw `oembed_json` payloads pruned after 90 days per NFR-11.
- [ ] Encrypted-at-rest audit test proves `platform_accounts` tokens are never stored or serialized in plaintext.
- [ ] Mobile `/settings/privacy` screen performs export and typed-confirmation account deletion end-to-end.

## Verification

```bash
cd apps/api
php artisan test --filter=Gdpr           # purge + export + encryption audit green
php artisan test --filter=Retention      # prune command tests green
# Manual account-deletion purge test:
php artisan tinker --execute="App\Models\User::factory()->create(['email'=>'purge@test.dev'])"
# authenticate as that user, then:
curl -X DELETE http://localhost:8000/api/v1/me -H "Authorization: Bearer <token>"
php artisan queue:work --once            # or fast-forward the grace delay in a test env
php artisan tinker --execute="dump(App\Models\PlatformAccount::count(), App\Models\Device::count())"  # 0 for that user
# Retention:
php artisan media:prune-originals && php artisan media:prune-originals   # second run: no-op (idempotent)
```

Manual: on the dev client, Settings → Privacy → export (confirm email/notification with working signed link), then delete account with typed confirmation → app returns to welcome; the same bearer token now gets 401.

## Gotchas

- **Never hard-delete users with ledger history** — `ledger_entries` is append-only (ADR-009); deleting the FK anchor breaks the audit trail and nightly balance checks. Anonymize the user row, keep the id.
- A user with a **pending payout or positive balance** deleting their account: hold the purge of financial linkage until settled; document this in the deletion confirmation copy (legal requirement to retain transaction records overrides erasure — cite in the records-of-processing map, NFR-10).
- `shares.user_id` FK is CASCADE in the schema — cascading would nuke published community places' attribution. Either relax to SET NULL in a migration or repoint to a sentinel user *before* deleting; test this explicitly.
- Unique constraints on `email`/`username` block "Deleted user" collisions — randomize (`deleted_{ulid}@reelmap.invalid`).
- Retention command must delete the **R2 object first**, then the row; if object delete fails, keep the row so the next run retries (don't leak orphaned storage). Watch `media_assets` unique `(sha256, source_post_id)` — the same file can back multiple source_posts; only delete the object when no other live row references that sha256/path.
- Export must exclude other people's PII embedded in the user's data (e.g. other sharers on the same place).
- Scheduler only runs if the Forge cron/`schedule:run` is configured (T-055) — note the dependency in the runbook.
