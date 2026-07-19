# T-072 — Admin moderation batch: force-reprocess + take-down

- **Phase:** M2 (moderation / ops) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-035 (Filament pipeline resources), T-071 (`PlaceStatus::Removed` + `tombstoneIfOrphaned` + `PlacePublisher`), T-013/T-024 (pipeline + status machine)
- **Target paths:** `apps/api/app/Services/Moderation/` (new: `ForceReprocessShare`, `PlaceModerator`, `ShareModerator`), `apps/api/app/Models/Share.php` (`forceResetStatus`), `apps/api/app/Jobs/Pipeline.php` + `ExtractPlaceData.php` (`forceExtract` flag), `apps/api/app/Services/Places/PlacePublisher.php` (`recountCounters`), `apps/api/app/Filament/Resources/{Shares,Places}/Tables/*Table.php`
- **Spec refs:** `07-risks-decisions.md` ADR-085, `04-analysis-pipeline.md`

## Context

Requested as the sequenced next epic after the personal-collection work. The
Filament admin panel at `/admin` was read-only — no way to take down bad content
or re-run extraction on a share whose pin came out wrong under an old prompt
version (poster≠venue, pre-multi-place, pre-v8). The pipeline actively forbids
re-running a `published` share (status guards + succeeded-run reuse + terminal
state), and the only reprocess path (`ShareController::retry`) refuses anything
but `Failed`/`fetch_unavailable`.

**Admin access already existed** (`users.is_admin`, `canAccessPanel`,
`reelmap:make-admin`, `AdminUserSeeder`, `->admin()` factory) — this task adds
only the moderation *actions*; granting a dev admin is a one-liner, not code.

## Key finding — two visibility gates (see ADR-085)

- Global map/browse/search filter **only** on `Place::scopePubliclyVisible`
  (`status IN (pending,active)`).
- Feed/profile cards additionally require `shares.status = Published` +
  `published_place_source_id` + `published_at`, AND the published place
  `publiclyVisible`.

⟹ `places.status = Removed` sits under **both** — it fully takes content down in
one column change.

## Product decisions (confirmed with Marcelo)

1. **Take-down reaches everywhere** (map pin *and* feed/profile cards).
2. **Removal is soft & reversible** (reuse `PlaceStatus::Removed`; no hard delete).
3. **Force-reprocess = fresh re-extract** (re-run the LLM; can re-map the pin).

## Scope

- **`ForceReprocessShare::run(Share, fromStage='extract')`** — delete the share's
  `place_sources`, recount/tombstone the freed places, `forceResetStatus` past the
  terminal guard, re-dispatch `Pipeline::chain(..., forceExtract: true)`. The flag
  skips `ExtractPlaceData::existingSuccess()` so the LLM re-runs. Idempotent
  (`unique(place_id, share_id)`; counters recomputed, not summed).
- **`PlaceModerator::takeDown/restore(places)`** — `Removed` ↔ natural status
  (Active if ≥2 published sources, else Pending). Skips Merged/Hidden.
- **`ShareModerator::takeDown(Share)`** — unpublish (`Rejected`, `admin_removed`) +
  null sources' `published_at` + recount; drops the pin only when this share was
  its last published contributor.
- **Filament** — custom `BulkAction`s + per-record `Action`s on the Shares and
  Places tables (admin-gated by the panel; confirmation dialogs). A `Removed`
  status filter so admins can find/restore taken-down places.

## Acceptance criteria

- An admin can bulk **force-reprocess** shares (incl. `published`) → the share
  re-extracts fresh, re-resolves, re-publishes; no duplicate pins; counts intact.
- An admin can **take down** a place → it disappears from the map, search, AND
  feed/profile cards; **restore** brings it back to its natural status.
- An admin can **take down** a share → its feed card drops; the pin drops iff no
  other user published it.
- Non-admins cannot reach the panel/actions.
- Gates green (Pest incl. new `tests/Feature/Moderation/*` + `Filament/ModerationActionsTest`, Pint, PHPStan L6); coverage not regressed.

## Out of scope (follow-ups)

- A "reprocess from `fetch`" (re-download) button — service already accepts `fromStage`.
- Hard permanent deletion (decided against).
- Bulk actions on other resources (Users, Tags, Reviews).
