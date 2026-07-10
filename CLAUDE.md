# CLAUDE.md — Reelmap

Guidance for any agent (or human) working in this repository. These rules are **mandatory**, not aspirational.

## Golden rules

1. **Nothing reaches `main` without a pull request.** Never commit, push, or merge directly to `main`.
2. **Before opening any PR**, run **`/coderabbit`** — it orchestrates the full pre-PR pass (quality gates → **`/simplify`** → **`/security-review`** → a grounded line-by-line review) and records the approval the PR gate requires. Fix every 🔴/🟡 it surfaces before the PR goes up.
3. **Any UI/frontend work uses the `/frontend-design` skill** — mobile screens, Filament customizations, any web UI.
4. **Every change ships with meaningful tests + coverage + E2E.** No trivial or placeholder tests.

## Branching & PR workflow

- Always branch from `main`: `feat/…`, `fix/…`, `chore/…`. Prefer one task (`T-###`) per branch; put the task id in the branch name and PR title.
- Never `git push origin main`, never fast-forward/merge your own work into `main` locally. `main` only advances through a reviewed, green PR.
- **Pre-PR checklist — all steps, in this order:**
  1. **Run `/coderabbit`** on the branch. It runs the whole pass end to end:
     - **Quality gates green** — API in the Sail container (`docker compose exec -T laravel.test composer lint && … stan && … test`); mobile / contracts (`npm run lint && npx tsc --noEmit && npm test`).
     - **`/simplify`** — apply the cleanups, then re-run the gates (simplify changes code).
     - **`/security-review`** — review the final, simplified code; fix anything it surfaces, re-run gates.
     - A grounded (gitleaks / semgrep / shellcheck) line-by-line review over every changed file.

     Fix every 🔴 Blocking finding (address 🟡 too, or justify), commit, and let it re-run until clean. You can still invoke `/simplify` and `/security-review` on their own, but `/coderabbit` is the one command that covers the checklist.
  2. **The approval is enforced.** A `PreToolUse` hook (`~/.claude/skills/coderabbit/scripts/pr-gate.sh`) blocks `gh pr create|edit|ready|merge` until `/coderabbit` has approved the **current** commit; any new commit invalidates the receipt → re-review. Don't route around the hook — fix the findings.
  3. **Open the PR** (`gh pr create`) with: summary, the `T-###` task id, and test evidence (what you tested and the results). Wait for CI green + review before merge.

  > `/coderabbit`, its scripts, and the gate hook are a **local, user-level** setup under `~/.claude` — they cover Claude Code sessions on this machine, not CI or PRs opened from the GitHub UI. (There is currently no server-side CI gate; add GitHub branch protection + a required status check when the project gains collaborators.)

## Testing standards (enforced)

- **Meaningful only.** Tests must assert real behavior across the **happy path AND failure/edge paths**. Banned:
  - `assertTrue(true)` / `expect(true)->toBeTrue()` and other no-op tests.
  - "Asserts 200 but never checks the body / side effects."
  - Snapshot-only tests, or tests that just mirror the implementation without exercising behavior.
  - Tests that pass whether or not the feature works.
- **Coverage is required.** Run coverage (`composer test -- --coverage` / Pest `--coverage`; mobile: `jest --coverage`) and do not regress it. New/changed code paths must be covered; call out any deliberate gap in the PR and why.
- **E2E is required for user-facing flows.**
  - API: full-pipeline / end-to-end feature tests driven by fakes+fixtures (e.g. share → published, redeem → verify → ledger).
  - Mobile: Maestro flows (see task T-053).
  - A feature is not "done" until its end-to-end path is green.
- Tests must run in **CI without network** — use fakes, fixtures, and recorded responses, never live third-party calls.

## UI / frontend

- Invoke **`/frontend-design`** for any screen, component, or visual change. Match the product's design system; do not ship generic, AI-looking UI.

## Dev environment (details in `apps/api/README.md`)

- **Local PHP is 8.2 — too old for Laravel 13.** Run all API tooling inside Docker (PHP 8.4+, Laravel Sail). The API is exposed on **`:8080`** locally (MAMP holds `:80`).
- Gates: `composer lint` (Pint), `composer stan` (PHPStan level 6 / Larastan), `composer test` (Pest, against Postgres — never sqlite, so citext/PostGIS are exercised).
- The **build plan and task queue live in `~/Sites/plans/reelmap`** (`tasks/tasks.json` is the source of truth); application code lives here. Follow the plan; record deviations as ADRs in the plan, never by editing the spec to match code.
