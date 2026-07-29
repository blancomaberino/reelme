# T-109 — Extract SourcePostResolver out of ShareController

- **Phase:** ARCH (audit wave 2) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-097
- **Target paths:**
  - `apps/api/app/Services/Ingestion/SourcePostResolver.php`
  - `apps/api/app/Http/Controllers/Api/V1/ShareController.php`
  - `apps/api/tests/Unit/Ingestion/`

## Context (codebase audit, 2026-07-29)

Audit finding A4 (2026-07-29). resolveSourcePost() is ~70 lines of domain logic in the HTTP layer, untestable except through a request. T-097 did the same extraction for the correction/merge engine (ExtractionCorrector) and is the pattern to follow.

## Acceptance criteria

- [ ] Services\Ingestion\SourcePostResolver owns the three-branch resolution (manual caption / URL / pure manual) and returns a ResolvedSource DTO
- [ ] ShareController::store keeps auth, the dedup/TOCTOU guard, the transaction and response shaping only
- [ ] Unit tests cover each branch directly, including the platform-enablement rejection, the >2048-char canonical URL guard, and the unknown-host placeholder-platform path (today only reachable end-to-end)
- [ ] The documented data-model gap (TODO(T-024/ADR): nullable platform / 'unknown') is either fixed here or recorded as an ADR in the plan repo
- [ ] No behavior change: existing share ingest feature tests pass unchanged
- [ ] Gates green: composer lint + stan + test

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
