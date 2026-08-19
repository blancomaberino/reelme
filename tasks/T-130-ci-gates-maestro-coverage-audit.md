# T-130 — CI runs none of the three gates CLAUDE.md calls mandatory

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-006 (CI: GitHub Actions), T-053 (E2E: Maestro flows)
- **Target paths:** `.github/workflows/ci.yml`, `apps/mobile/e2e/`,
  `apps/mobile/.maestro/`, `.claude/skills/` (`/gates`), `apps/api/composer.json`

Code review 2026-08-19, finding **CR-13**, plus the security review's one
unranked infrastructure gap (no dependency audit anywhere). Same file, same root
cause: **CLAUDE.md declares gates that CI does not run.**

## Context

### Two Maestro directories, and the older one is rotten

- `e2e/feed-search.yaml` taps a `Feed` tab and asserts the text "Feed". There is
  **no feed route** in `app/(main)/_layout.tsx` — the tabs are map, places,
  search, wallet, profile; the global feed was replaced.
- `e2e/feed-search.yaml` and `e2e/map-flow.yaml:38` both register **without a
  birthdate**, which T-113 made required. Neither can get past "Create account".
- `e2e/auth-flow.yaml` **was** updated for the age gate. `.maestro/` is the
  maintained set.

CI has no Maestro step at all, so nothing detected the rot — and *"E2E is
required for user-facing flows"* has been unenforced since it was written.

### So is coverage

Neither gate runs `--coverage`, though CLAUDE.md requires it and forbids
regressing it.

### And dependency advisories are unverified

The security review recorded honestly that `osv-scanner` was not installed and it
therefore **did not guess** at advisories. `composer audit` and `npm audit` are
absent from the `/gates` matrix. Small addition here; closes the review's only
genuinely unexamined area.

## Implementation

- One Maestro directory. Port or delete `e2e/*.yaml`.
- Add a Maestro job to `ci.yml` — **or**, if a simulator runner is genuinely not
  affordable, a flow-lint that parses each yaml and checks every `testID`/text
  against the app's source. Either way it must be able to **fail**.
- Coverage in CI for API and mobile, with a recorded baseline the job compares
  against.
- `composer audit` and `npm audit` in the matrix.
- Mirror all of it in `/gates`, so a local pass and a green check mean the same
  thing.

## Acceptance criteria

- [ ] ONE Maestro directory; every remaining flow registers with a birthdate and
      taps only tabs that exist
- [ ] CI runs Maestro (or the flow-lint fallback) and it FAILS on a deliberately
      broken flow — proven both ways
- [ ] Coverage runs for API and mobile and produces a number; the job fails on a
      regression against the baseline
- [ ] `composer audit` and `npm audit` run and fail on a known-vulnerable fixture
- [ ] `/gates` mirrors the CI matrix

## Gotchas

- **Be honest about the runner.** A Maestro job needs a simulator, which is a real
  cost decision on GitHub-hosted runners. A README asking people to remember is
  not a gate. What is *not* acceptable is leaving it as it is: two directories,
  one broken, nothing running either.
- A coverage baseline that is simply "current" will lock in whatever is currently
  uncovered. Record it, and say in the PR what it is — a number nobody looked at
  is the same failure as a gate nobody ran.
- This is the third gate-that-cannot-fail in the queue (T-114 `expo lint`, T-116
  contracts lint). If a shared "prove this gate can fail" harness comes out of
  T-116, reuse it rather than writing a third one.
