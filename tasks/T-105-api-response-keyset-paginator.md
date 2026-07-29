# T-105 — API: ApiResponse envelope helper + generic KeysetPaginator

- **Phase:** ARCH (audit wave 2) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-030, T-071
- **Target paths:**
  - `apps/api/app/Http/ApiResponse.php`
  - `apps/api/app/Support/KeysetCursor.php`
  - `apps/api/app/Http/Controllers/Api/V1/`
  - `apps/api/tests/Feature/`

## Context (codebase audit, 2026-07-29)

Audit finding A1 (2026-07-29). The envelope and keyset pagination are reimplemented 9 times in 4 different flavors; PaginatesPlaces solved it for places only. Pure cleanup, roughly -150 lines, and it makes the envelope shape enforceable in one place (which T-102's contract tests then pin).

## Acceptance criteria

- [ ] An ApiResponse helper owns the {data, meta} envelope (item / collection / keyset / noContent) and replaces the ~20 hand-written 'meta' => (object) [] literals
- [ ] A generic KeysetPaginator owns the limit+1 -> hasMore -> encode-cursor pattern; PaginatesPlaces delegates to it rather than reimplementing it
- [ ] All 9 next_cursor call sites (ReviewController, PlaceController x2, ProfileController x2, FollowController, TagController, FeedController, ShareController) use the shared path
- [ ] Byte-identical responses: existing feature + contract tests pass unchanged, no client-visible diff
- [ ] Gates green: composer lint + stan + test

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
