---
name: gates
description: Run Reelmap's quality gates (Pint, PHPStan, Pest in the Sail container; ESLint, tsc, Jest for mobile and contracts) for the areas this branch actually touches. Use before opening a PR, after /simplify changes code, or whenever asked to "run the gates / lint / tests".
disable-model-invocation: true
---

# Quality gates

Run the gates the same way CI does, for the areas this branch actually touches:

```bash
.claude/skills/gates/run-gates.sh
```

That is the whole skill. The script mirrors the `changes` path-filter job in
`.github/workflows/ci.yml`, so a green run here predicts a green CI run.

## Arguments

| Invocation | Runs |
| --- | --- |
| `run-gates.sh` | gates for areas changed vs `main`, including uncommitted work |
| `run-gates.sh --all` | every gate regardless of the diff |
| `run-gates.sh api mobile` | only the named areas (`api`, `contracts`, `mobile`) |

## What runs where

- **api** → `composer lint` (Pint), `composer stan` (PHPStan level 6), `composer test` (Pest on Postgres) — all **inside the Sail container**. Local PHP is 8.2 and cannot run this codebase; the script refuses rather than falling back to the host.
- **contracts** → regenerate + `git diff --exit-code` drift check, `typecheck`, Jest. Editing a schema without committing the regenerated `src/generated` is the single most common red build.
- **mobile** → ESLint, `tsc --noEmit`, Jest. A `packages/contracts` change selects **both** contracts and mobile, exactly as CI does.

## Reading the result

Every selected gate runs even after an earlier one fails, so one invocation gives
you the complete list. The summary block at the end is the thing to report; exit
status is non-zero if anything failed.

## Notes

- **API gates need the stack up.** If `laravel.test` isn't running the script says
  so and points at `./scripts/dev.sh backend` — start it, then re-run.
- **Re-run after `/simplify`.** Simplify rewrites code; the gates it was green
  against no longer apply. The pre-PR checklist in `CLAUDE.md` requires this.
- This does **not** replace `/coderabbit`. Gates are step one of the pre-PR pass;
  `/coderabbit` runs them plus `/simplify`, `/security-review`, and the grounded
  line-by-line review, and records the receipt the PR gate hook checks.
