---
name: task
description: Drive the Reelmap T-### task lifecycle — pick the next ready task from the plan queue, branch correctly, and close it out with the mandatory completion report. Use when asked to "start the next task", "work on T-###", "what's next", or to mark a task done.
disable-model-invocation: true
---

# T-### task lifecycle

The plan repo (`~/Sites/plans/reelmap`) is the source of truth: `tasks/tasks.json`
holds state, and each task has a companion `tasks/T-###-*.md` brief. Application
code lives in `~/Sites/reelmap`. **Follow the plan** — when reality forces a
deviation, record it as a note/ADR in the plan; never edit the spec to match code.

Helper (all state changes go through it, so status and notes never drift):

```bash
python3 .claude/skills/task/task.py next          # ready tasks, priority order
python3 .claude/skills/task/task.py show T-039    # record + full brief
python3 .claude/skills/task/task.py start T-039   # -> in_progress + branch name
python3 .claude/skills/task/task.py note T-039 "…"  # dated note (deviations, partial work)
python3 .claude/skills/task/task.py done T-039    # -> done
python3 .claude/skills/task/task.py status        # counts by phase
```

## Picking a task

Run `next`. It applies the ordering rule recorded in `tasks.json`: **ARCH is the
current priority phase**, worked before the remaining M1/M3/M4/M5 backlog, honoring
`depends_on` within it. If something is already `in_progress`, finish or explicitly
park it before starting anything new.

Confirm the choice with the user unless they already named a task.

## Working it

1. **`start T-###`**, then branch from `main` with the printed name — the id goes in
   the branch **and** the PR title. Pick the prefix by kind: `feat/`, `fix/`, `chore/`.
2. **Read the brief** (`show T-###`) and treat its acceptance criteria as the
   definition of done. For "how does X work" questions prefer `graphify query`.
3. **UI work → `/frontend-design`.** Mobile screens, Filament customizations, web UI.
4. **Tests ship with the change** — happy path *and* failure/edge paths, coverage not
   regressed, E2E for user-facing flows. No placeholder tests.
5. **`/gates`** as you go; **`/coderabbit`** before the PR (it runs the gates,
   `/simplify`, `/security-review`, and the line-by-line review, and records the
   receipt the PR-gate hook requires).
6. **Open the PR** with summary, the `T-###` id, and test evidence. Never push to `main`.

If the task ships only partly, `note` what landed and what remains, and leave the
status `in_progress` — don't mark it done.

## Closing out

`done T-###` only after the PR is merged (or the user says it's complete). Then end
your reply with the mandatory completion report — this is golden rule #7, not optional:

> **✅ Task:** `T-###` — <title>
> **What it is:** 1–2 sentences on the user- or operator-facing effect, not the file list.
> **How to test it manually:**
> - **Admin dashboard (Filament, http://localhost:8080/admin):** exact click-path — resource/page, what to enter, what you should see.
> - **Simulator / device (Expo dev client):** exact in-app steps — screen, what to tap, what should appear. Note when a step needs the queue worker (`./scripts/dev.sh backend`), a physical device, or seeded data.
> - **Backend-only:** the concrete artisan/tinker/`curl http://localhost:8080/api/v1/…` command and expected result (a Mailpit mail at http://localhost:8025, a DB row, a 200 body).

Use whichever surfaces actually exercise the change. If there is genuinely no manual
surface (pure refactor, config, test-only), say so and give the command that proves it
still works instead of inventing a click-path.

**Mobile tasks:** green Jest is not evidence. Verify in the simulator, and if the change
adds a native module or touches `app.config.ts` plugins, a full dev-client rebuild is
required before the behavior is real — see the `native-rebuild-checker` agent.
