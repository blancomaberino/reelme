# T-097 — ARCH/P1: extract ShareController::update() merge engine → ExtractionCorrector

- **Phase:** ARCH (P1 testability/SRP) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-024 (review/publish flow)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/ShareController.php`,
  `apps/api/app/Services/Places/`, `apps/api/tests/`

## Context (audit finding, 2026-07-21)

`ShareController` (525 lines, largest in the app) embeds a recursive JSON merge/diff engine in
`update()`: `applyCandidate`/`deepMerge`/`mergePlaces`/`recordCorrections`/`flattenLeaves`
(~150 lines, :203-352) — untestable without booting the HTTP layer and unreusable by, e.g., a
Filament "reprocess" action that needs the same merge semantics.

## Implementation

- Move that logic into `App\Services\Places\ExtractionCorrector` with a testable signature
  (`applyCorrection(Share, array $extraction, ?array $candidate): array` + `recordCorrections`).
- `ShareController::update()` keeps validation + delegation + response shaping only.

## Acceptance criteria

- [ ] Merge/diff/correction logic lives in `ExtractionCorrector`; controller is thin
- [ ] Unit tests exercise the corrector directly (deep-merge, place merge, correction recording)
      without HTTP; existing `update()` feature tests stay green
- [ ] Corrector is reusable by an admin/reprocess path
- [ ] Gates: `composer lint` + `stan` + `test` green

## Log

- **2026-07-21** — Filed from the architecture audit.
