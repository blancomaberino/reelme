# CLAUDE.md — Reelmap

Rules for every agent working in this repo. **Mandatory.** Each rule is one line;
the procedure lives in the named skill, the evidence in
[`docs/process/lessons.md`](docs/process/lessons.md). If a skill and this file
disagree, this file wins.

## 0. Talking to the owner

- **Answer first, in ≤ 10 lines.** Result, decision, what they must do. No
  reasoning, no narration, no survey of options you did not take — unless asked.
- Detail goes in the commit message or PR body, never the chat.
- The completion report (§6) is ≤ 8 lines. Bullets over prose. No walls of text.

## 1. Golden rules

1. **`main` moves only by reviewed PR.** Never commit, push, or merge to `main`.
2. **Think before code.** Every task starts with the design brief (§3) — the
   reviewers' questions answered *before* the code exists, when they are free.
3. **One review round, not eight.** `/simplify` → gates → ONE concurrent review
   → batch every finding → one fix commit → narrow re-review → both receipts (§4).
4. **Audit scope follows the diff.** `select-lanes.sh` decides the seats. Docs-only
   (`docs/`, `README.md`, top-level `*.md`) runs nobody; the guard — `.claude/**`,
   any `CLAUDE.md`, `.github/`, `scripts/` — always gets Security + Architecture.
5. **Tests ship with the change** — happy path, failure path, and for any filter
   a row that must be EXCLUDED. Coverage never regresses. E2E for user flows.
6. **Wiring over code** — reachable from an existing screen, sibling reused not
   re-implemented, the interaction re-asks, no mock that silences a crash, and a
   rule on state covers *every* writer of that state.
7. **UI work uses `/frontend-design`.**
8. **Verify on the device, then restore it.** Maestro drives the simulator;
   `simctl openurl` never navigates (a hook denies it); location back to
   Montevideo `-34.9011,-56.1645`; screenshot where Cmd+R lands.
9. **End every task with the completion report** (§6). Never describe a
   click-path you have not walked.

## 2. Workflow per task

| Step | Do | Done when |
| --- | --- | --- |
| Pick | `python3 .claude/skills/task/task.py next` → `start T-###` → branch `feat/t-###-…` from `main` | brief read, acceptance = definition of done |
| Brief | fill the design brief `start` prints, into `.claude/state/HANDOFF.md` | every question answered or marked n/a; plan review run if §3 says so |
| Build | code + tests together; iterate with `composer test -- --filter=X` / `jest <path>` | acceptance met on the device or by curl |
| Polish | `/simplify`, then `.claude/skills/gates/run-gates.sh` (full suite, **once**) | gates green on the final tree |
| Review | `/coderabbit` — one round: grounding + coverage + specialists + the `select-lanes.sh` seats + `/security-review`, **all launched in one message** | every 🔴/🟡 verified against the code |
| Fix | batch all findings → **one** commit → gates → re-seat only Security, Architecture and the lanes whose code changed | round ≤ 2 (§4) |
| Receipts | `record-receipt.sh` (audit) and `approve.sh` (coderabbit), together, on the final commit | both match HEAD + tree |
| PR | `gh pr create` with summary, `T-###`, test evidence | CI green; bot findings fed back to the skill checklist |

Mobile tasks add: `native-rebuild-checker` before "done"; `prebuild --clean`
after any `app.config.ts` or native dependency change.

## 3. Design brief (before the first line of code)

Write it in `.claude/state/HANDOFF.md` (git-ignored on purpose: it is working
state, not a deliverable); `task.py start` prints the template.

- **Entry point** — which existing screen/route/command reaches this? Which test presses it?
- **Sibling** — what existing map/list/form/sheet/query does this extend? What gets extracted?
- **State & writers** — every state given a new consequence, and *every* place that
  writes it (grep `set({ field`, `->update([`, `fill(`, direct assignment).
- **Contract ends** — Resource ↔ JSON Schema ↔ mobile TS: which change together?
- **Data** — migration? index? backfill? rollback? What does a hostile input reach (DB, logs, Sentry)?
- **Authz** — who may call this, and where is that checked?
- **Tests** — the failure cases and the excluded-row case, named now.
- **Native** — new module or plugin? Then a rebuild is part of the task.
- **Out of scope** — what you will *not* do, so the review does not expand it.

**Plan review before code** (2 agents, one message, `Software Architect` +
`Senior SecOps Engineer`, over the brief) when the task touches auth, money,
a migration, a public contract, or ≥ 3 layers. Ten minutes here replaces the
rounds that T-156 spent on a file the task never named.

**Fix the shape, not the instance.** A second finding in the same file means the
first fix enumerated cases; replace it with the rule that covers them.

## 4. Review, audit and the gates

- **Hooks enforce:** no `migrate:fresh`/`db:wipe` on dev (`REELMAP_ALLOW_DB_WIPE=1`
  only — `--env=testing` does not reach the test DB and is refused too); no
  `simctl openurl`; no push / `gh pr create|edit|ready|merge|reopen` without an
  audit receipt matching HEAD + tree (`guard-pr-audit.py`) and a `/coderabbit`
  approval for HEAD (`pr-gate.sh`, user-level). Also on save: Pint in the
  container for `apps/api/**/*.php`, contracts regeneration for a schema edit.
- **The DB guard reads Bash only.** Laravel Boost's `tinker` and `database-query`
  MCP tools reach the dev database and bypass it — treat them as write access.
- **Seats:** `.claude/skills/audit-agency/select-lanes.sh` prints them. Security
  (`Senior SecOps Engineer`) and Architecture (`Software Architect`, never
  `Backend Architect` in its place) sit on every non-docs diff. Verify each
  finding against the cited lines before applying it.
- **Rounds:** at most two. A third round of findings in one file means the design
  is wrong — stop, redesign, then review once.
- **Escape hatches are owner-approved only** and must be justified in the PR
  body: `REELMAP_SKIP_AUDIT=1`, `ALLOW_UNREVIEWED_MERGE=1`, `--panel-skipped`.
- **Owner-approved only to edit: anything a gate reads to decide whether a check
  is REQUIRED or whether it PASSED, whatever it is called** — including
  `pr-gate.sh`, `approve.sh`, `record-panel.sh`, `check-review-threads.sh`,
  `parse-review-threads.py`, the two `select-*-panel.sh`, `guard-pr-audit.py`,
  `record-receipt.sh`, `select-lanes.sh`, the hook lines in `.claude/settings.json`,
  `run-gates.sh`, and the gates' own tests. Findings about them go to the owner,
  not into them. What a review loop MAY edit is judgement the gate never reads:
  `review-checklist.md` and `ground.sh` heuristics.
- **A branch you did not write runs its own `.claude/**`** — the gates, the
  selector and the hooks' tests exec files from the diff. Read `.claude/**` in
  the diff before running any of them on a contributor's branch.
- **After the PR opens:** GitHub's CodeRabbit reviews once; every later push needs
  `@coderabbitai review`. Confirm a round by its body, not its check. Every bot
  finding the local pass missed goes into the skill's checklist in the same
  session (`~/.claude/skills/coderabbit/references/review-checklist.md`).

## 5. Testing standards

- Banned: `assertTrue(true)`, status-only assertions, snapshot-only tests, tests
  that pass with the feature deleted, mocks that invent an id/testID/route.
- Prove a guard bites: mutate it, watch the test fail, restore with an absolute path.
- Tests run without network — fakes, fixtures, recorded responses.
- API: Pest on Postgres, never sqlite. Mobile: Jest + Maestro flows.

## 6. Completion report (mandatory, ≤ 8 lines)

> **✅ Task:** `T-###` — title
> **What it is:** one sentence, user- or operator-facing effect.
> **How to test:** the exact path on the surface that exercises it —
> Filament (`http://localhost:8080/admin` → resource → action → expected),
> simulator (screen → tap → expected; note worker/device/seed needs), or
> `curl http://localhost:8080/api/v1/…` / artisan → expected result.
> No manual surface? Say so and give the command that proves it works.

## 7. Dev environment (hard facts)

- Start everything with `./scripts/dev.sh` (`backend` / `run` / `start` / `stop`).
- Local PHP is 8.2; **all API tooling runs in the Sail container**
  (`docker compose exec -T laravel.test composer lint|stan|test`). API on `:8080`.
- Pest flags after `--`. Exit 124 = stopped by a bound, 137 = SIGKILL or OOM,
  neither is "failed". Never pipe the run through `tail`/`grep`. **Never run two
  suites at once.** Full suite ≈ 8 min: run it once, on the final tree.
- Queue worker and Metro cache code in memory: `dev.sh backend` cycles the
  worker; `expo start --dev-client --clear` fixes stale JS.
- Plan and task queue: `~/Sites/plans/reelmap` (`tasks/tasks.json` is truth).
  Deviations become ADRs there; never edit a spec to match code.
- Knowledge graph: `graphify query "<question>"` before a cold grep (the
  `graphify-repo` skill says when it needs a rebuild). `dev-environment` skill
  for the boot modes; `REELMAP_PLAN_DIR` if the plan checkout moved.
- Coverage: `composer test:coverage` (API), `jest --coverage` (mobile); never regress.
- **Subagents by default.** Agent Teams is enabled but costs scale with size;
  propose a team only for ≥ 3 substantial independent workstreams, and let the
  owner opt in. Personal overrides go in `.claude/settings.local.json`.

## 8. Where things live

| Thing | Path |
| --- | --- |
| Gates runner | `.claude/skills/gates/run-gates.sh` |
| Task lifecycle | `.claude/skills/task/` |
| Audit seats + receipt | `.claude/skills/audit-agency/` |
| Hooks | `.claude/hooks/` (tests run by the `tooling` gate) |
| Project agents | `contract-consistency-reviewer`, `native-rebuild-checker` in `.claude/agents/` |
| `/coderabbit` (user-level, `~/.claude/skills/coderabbit`), `/simplify`, `/security-review` (built in) | not in this repo |
| Handoff note | `.claude/state/HANDOFF.md` (update as you go) |
| Lessons | `docs/process/lessons.md` |
