# CLAUDE.md — Reelmap

Guidance for any agent (or human) working in this repository. These rules are **mandatory**, not aspirational.

## Golden rules

1. **Nothing reaches `main` without a pull request.** Never commit, push, or merge directly to `main`.
2. **Before opening any PR**, run **`/coderabbit`** — it orchestrates the full pre-PR pass (quality gates → **`/simplify`** → **`/security-review`** → a grounded line-by-line review) and records the approval the PR gate requires. Fix every 🔴/🟡 it surfaces before the PR goes up.
3. **Any UI/frontend work uses the `/frontend-design` skill** — mobile screens, Filament customizations, any web UI.
4. **Every change ships with meaningful tests + coverage + E2E.** No trivial or placeholder tests.
5. **Finish every task with a completion summary** — see [Task completion report](#task-completion-report). Whenever you finish working on a task, end with: what task, what it's about, and how to manually test it (admin dashboard or simulator).

## Agent orchestration (teams vs subagents)

Agent Teams is enabled on this machine (`CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1`), but it is **not** the default way to work — a team's token cost scales with its size, so it must earn its keep.

- **Default to the subagent architecture** — the `Task`/`Agent` tool, Workflows, or just doing the work inline. Use it for the everyday case: single-task changes, focused searches, sequential edits, one-off reviews, and most `T-###` tasks.
- **Reserve a team of teammates for genuinely team-worthy work** — large, parallelizable efforts where independent agents *coordinating with each other* adds real value that outweighs the cost:
  - a multi-workstream epic that splits cleanly across layers (e.g. backend + mobile + web in parallel),
  - a broad migration / audit / sweep across many files,
  - design or architecture explored via competing approaches in parallel (proposer + skeptic).
- **Bar for forming a team:** the work decomposes into **≥3 substantial, independent workstreams** that run in parallel with low coordination overhead. If one agent — or a couple of sequential subagents — can do it, do **not** form a team.
- When unsure, prefer subagents. **Propose** a team (one-line rationale + rough scope) and let the user opt in, rather than forming one silently.

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

## Task completion report

**Whenever you finish working on a task, end your reply with a short completion summary.** This is mandatory — it's how the owner knows what shipped and how to verify it by hand. Give it every time you wrap a task (whether the work merged, is awaiting merge, or is a WIP hand-off), not only at PR time. Format:

> **✅ Task:** `T-###` — <title>
> **What it is:** 1–2 sentences on what changed and why (the user-facing / operator-facing effect, not the file list).
> **How to test it manually:**
> - **Admin dashboard (Filament, http://localhost:8080/admin):** the exact click-path — which resource/page, what to enter, what you should see. Use this for anything with an admin/moderation/data surface.
> - **Simulator / device (Expo dev client):** the exact in-app steps — which screen, what to tap/share, what should appear. Use this for any mobile-facing flow. Note when a step needs the queue worker running (`./scripts/dev.sh backend`), a physical device (share sheet, push tokens), or seeded data.
> - **Backend-only / no UI surface:** give the concrete artisan/tinker/`curl http://localhost:8080/api/v1/…` command and the expected result (e.g. a Mailpit email at http://localhost:8025, a DB row, a 200 body).

Pick whichever surface(s) actually exercise the change — don't invent an admin path for a mobile-only feature or vice-versa. If a change genuinely has no manual surface (pure refactor, config, test-only), say so explicitly and give the command that demonstrates it still works (the relevant gate, a migration check, etc.) instead of pretending there's a click-path.

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

### Starting the local environment

Always use **`./scripts/dev.sh`** (repo root) — never hand-roll `docker compose` + worker + expo commands. For the mode reference (`backend` / `run` / `start` / `stop` / `android`), what each one boots, and the device caveats, see the **`dev-environment`** skill.

> ⚠️ **Never run `php artisan migrate:fresh` (or `db:wipe`) against the dev DB** — artisan's default connection is the dev Postgres, so it **wipes dev data**. Use plain `migrate` on dev; the Pest suite uses a separate testing database. Only wipe dev when the user explicitly asks (e.g. "clear the DB").
>
> This rule is **enforced**, not advisory: a `PreToolUse` hook blocks those commands outright. Neither `--env=testing` nor `--database=testing` gets around it — verified, neither points at the test database. The only override is `REELMAP_ALLOW_DB_WIPE=1`.

### Other

- **Local PHP is 8.2 — too old for Laravel 13.** Run all API tooling inside Docker (PHP 8.4+, Laravel Sail). The API is exposed on **`:8080`** locally (MAMP holds `:80`).
- Gates: `composer lint` (Pint), `composer stan` (PHPStan level 6 / Larastan), `composer test` (Pest, against Postgres — never sqlite, so citext/PostGIS are exercised).
- The **build plan and task queue live in `~/Sites/plans/reelmap`** (`tasks/tasks.json` is the source of truth); application code lives here. Follow the plan; record deviations as ADRs in the plan, never by editing the spec to match code.

### Automation in `.claude/` (checked in — shared, not personal)

Unlike `/coderabbit` and graphify (user-level setups on one machine), the following live in the repo and apply to everyone:

- **`/gates`** — runs the gate matrix for the areas the branch touches, mirroring the path filters in `.github/workflows/ci.yml`. Use it as you work; it does **not** replace `/coderabbit`, which is still the mandatory pre-PR pass.
- **`/task`** — drives the `T-###` lifecycle over the plan queue (`next` / `show` / `start` / `note` / `done`) and carries the completion-report template. Set `REELMAP_PLAN_DIR` if your plan checkout isn't at `~/Sites/plans/reelmap`.
- **Agents** — `contract-consistency-reviewer` (a payload shape must agree across the API Resource, the JSON Schema, and the mobile TS; `tsc` and Pest each see only one seam) and `native-rebuild-checker` (JS-only vs full dev-client rebuild, so mobile work isn't called done on green Jest alone).
- **Hooks** (`.claude/settings.json`) — the dev-DB guard above; Pint-on-save for `apps/api/**/*.php` (run in the container, since local PHP is 8.2); and contract regeneration when a `packages/contracts` schema is edited, because stale generated output is an automatic CI failure.
- **`.mcp.json`** wires **Laravel Boost** over `docker exec`. Boost's `tinker` tool reaches the dev database and is **not** covered by the Bash guard above — it inspects shell commands only.

`.claude/settings.local.json` is git-ignored: put personal overrides there.

### Codebase knowledge graph (graphify)

This repo is mapped with **graphify**, a local (git-ignored, never checked in) knowledge graph of the codebase. For **"how does X work / what connects to Y / trace the flow through Z"** questions, prefer **`graphify query "<question>"`** over a cold grep — it already knows the cross-cutting bridges. See the **`graphify-repo`** skill for how it's built, when it auto-refreshes, and when you must rebuild it by hand.
