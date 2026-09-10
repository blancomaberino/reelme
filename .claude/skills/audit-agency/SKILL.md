---
name: audit-agency
description: Seat the project's agency agents over the branch as independent, read-only reviewers before a PR is opened or updated — the seats chosen by select-lanes.sh from the files the diff touches, Security and Architecture on every non-docs diff, nobody on docs-only. Use before creating a PR, before any push that updates one, and whenever asked to "audit the branch / run the agency / review this like a team".
---

# Agency audit

Read-only reviewers over the branch, each reading the same diff as a different
specialist. Enforced by `.claude/hooks/guard-pr-audit.py`: no push and no
PR-mutating `gh` command until a receipt matches HEAD **and** the working tree.

**Authority:** `CLAUDE.md` §4 states the rule; this file is the procedure. When
they disagree, `CLAUDE.md` wins and this file is corrected.

## 0. Run the grounding pass first — the seats are one axis, not the review

```bash
.claude/skills/audit-agency/run-grounding.sh
```

`/coderabbit` (CLAUDE.md §2) seats these lanes as its Phase 3.5, beside a
grounding pass that is a SCRIPT: gitleaks, semgrep, osv-scanner, actionlint,
hadolint, shellcheck, and heuristics for wrong-reason assertions, hand-written
mirrors and check-then-act races. Seating the lanes alone skips the half that
cannot be argued out of a finding, and it is an easy thing to skip because
seating lanes feels like reviewing. A 2026-09 session did exactly that across
five commits (`docs/process/lessons.md`).

`record-receipt.sh` refuses without a marker for the current tree, so the two
halves cannot come apart. Every ⚠️ it prints is a LEAD to verify against the
diff, not a finding — the script never fails on findings, only on failing to run.

## 1. Pick the seats — do not guess

```bash
.claude/skills/audit-agency/select-lanes.sh
```

It reads the working tree against `main` and prints the seats with a reason each,
or `LANES: none` for a docs-only diff (then record `docs-only` and stop — the
script refuses that verdict on anything else). Rules it applies:

| Diff touches | Seats added |
| --- | --- |
| anything but docs | `Senior SecOps Engineer`, `Software Architect` (always) |
| a `.claude/`, `.github/` or `scripts/` dir at any depth, any `CLAUDE.md`/`AGENTS.md`, `.mcp.json` | + `Code Reviewer` (the guard is prose; a check that cannot fail is the bug) |
| `apps/api/` | + `Backend Architect`; app/routes/database → + `Code Reviewer` |
| migrations / `.sql` | + `Database Optimizer` |
| `apps/mobile/` | + `Mobile App Builder`; a screen/component → + `UX Architect`, `UI Designer`; app.config/package.json → + `native-rebuild-checker` |
| Resources, `packages/contracts`, `apps/mobile/src/api` | + `contract-consistency-reviewer` |
| Filament / views | + `UX Architect` |
| auth / money / secrets in the content | + `Application Security Engineer`; money → + `Payments & Billing Engineer` |

Docs means a `.md` file in `docs/`, `apps/*/docs/`, a `README.md`, or the top
level — both the extension and the place. A `.md` under `resources/` is an LLM
prompt the API executes; a `.php` under `docs/` is a Filament page. Files no rule knows are printed
as `UNMATCHED`; add a rule (owner-approved, with a test) rather than a seat to
your prompt. **The selector is a script from the branch under review** — on a
branch you did not write, read `.claude/**` in the diff before running it.

## 2. Launch every seat in ONE message

One `Agent` call per seat, same message, so they run concurrently. This round is
also where `/coderabbit`'s specialists and `/security-review` run — one fan-out,
not three stages. Give every seat the same frame:

- **READ-ONLY.** Findings only; you apply them.
- Scope: `git diff main...HEAD` plus the working tree. Name the files in their lane.
- Read `CLAUDE.md` §1, §3, §5 first.
- **Brief the surface, not the diff.** Say what the change makes true ("`near`
  now reaches Sentry"; "locale now invalidates places") and ask for everything
  that already depends on it — the second writer lives in unchanged code.
- Every finding: severity (🔴 blocking / 🟡 should-fix / 🔵 nit), `file:line`,
  and a concrete failure scenario. Reject any finding they cannot make concrete.
- A clean lane gets one line. Cap ~600 words. No file dumps.
- Check every new COMMENT against the file it makes a claim about, not only the
  one it cites.

## 3. After they report

1. **Verify before fixing.** Read the cited lines; agents are confidently wrong sometimes.
2. **Fix every 🔴 and 🟡**, or get an explicit owner waiver. A product decision is
   the owner's call — surface it, do not implement your own answer.
3. **Fix the shape, not the instance.** Two findings in one file that each add a
   case mean the fix is a list; replace it with the rule (T-156 converged only
   when `SentryScrubber` matched on shape instead of naming SDK fields).
4. **Batch into ONE commit.** Both receipts die on the next commit — so collect
   every seat's findings, apply them together, then re-seat once.
5. **Prove each fix bites.** Every test owes one observed failing run — red
   first, or revert the fix, or mutate the production code, restoring with an
   absolute path. Say which you did (CLAUDE.md §5 owns the rule).
6. **Re-run the gates for the areas the fix touched** (`run-gates.sh api`, …); fixes are code.
7. **Re-review, narrowly:** Security and Architecture always, plus only the lanes
   whose code the fix commit touched. **Round limit: two.** A third round of
   findings in the same file means the design is wrong — stop, redesign, review once.
8. **Commit, then record** — the receipt covers the tree:

```bash
.claude/skills/audit-agency/record-receipt.sh findings-fixed "3 🟡: contract guard, hours reporting path, review cap" --declines none
.claude/skills/audit-agency/record-receipt.sh findings-fixed "2 🟡 fixed, 1 deferred" --declines "T-172: two clocks on the listings — owner waived, filed"
.claude/skills/audit-agency/record-receipt.sh clean "nothing raised" --declines none
.claude/skills/audit-agency/record-receipt.sh docs-only        # the one verdict the script can prove, so the one exempt from --declines
```

The receipt stores `required_lanes`, `selector_changed_by_this_diff` (the receipt
was produced by code the diff changed — Code Reviewer is mandatory then) and
`declines`. It still cannot tell which seats you filled — that stays on you.

`--declines` is required for every verdict except `docs-only`, which the script
proves against the selector. Say `none`, or say what was declined or bounded and
who waived it — CLAUDE.md §4 needs an owner waiver for a 🔴 or 🟡. **Put the same
text in the PR body**: nothing reads this field yet, so the PR is where it gets a
reader. A bounded finding goes here too; the flag is named for the common case,
not the only one.

## Notes

- The receipt hashes HEAD and the tree's CONTENT; any commit, amend, rebase or
  edit invalidates it.
- The gate is a process check, not a security boundary — a subshell or alias gets
  through, accepted. `REELMAP_SKIP_AUDIT=1` is owner-approved only and is
  justified in the PR body.
- Tests: `bash .claude/skills/audit-agency/tests/select-lanes.test.sh` (the
  selector) and `tests/guard.test.sh` (the hook's own suite). The `tooling` gate
  runs both on any `.claude/` change.
- Findings converging from two lanes are the ones to trust most; a lone finding
  deserves the hardest look before you act.
