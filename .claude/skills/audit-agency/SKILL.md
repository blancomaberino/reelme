---
name: audit-agency
description: Fan the project's agency agents over the branch as independent specialist reviewers before a PR is opened or updated — security and architecture always seated, plus the lanes the diff touches (mobile, backend, UX, UI, test quality). Use before creating a PR, before any push that updates an open one, and whenever asked to "audit the branch / run the agency / review this like a team".
---

# Agency audit

Read-only reviewers over `main...HEAD`, each reading the same diff as a
different specialist. Enforced: `.claude/hooks/guard-pr-audit.py` blocks a push
and the PR-mutating commands until a receipt matching HEAD **and** the working
tree's content exists.

The gate has its own tests — `python3 .claude/skills/audit-agency/test-guard.py`
(23 cases). Run them after touching the hook. Three of them are bypasses the
panel drove through the first version of this gate within minutes of it being
written, two needing nothing more exotic than a flag that takes a value
(`git -C <dir> push`); they are kept as regressions rather than as a paragraph
claiming the hole is closed.

**Authority:** CLAUDE.md states the *rule* (Golden Rule #3, pre-PR checklist
step 2) — when the panel runs and which seats are mandatory. This file is the
*procedure* — lanes, prompt discipline, receipts. If the two ever disagree about
the rule, CLAUDE.md wins and this file is the one to correct.

## Why this exists alongside the other gates

The gates prove the code does what it says. The line-by-line review reads the
diff. Neither asks *"can a user reach this"*, *"is this the second
implementation of something we already have"*, *"does this test pass whether or
not the feature works"* — the questions in
[Wiring & seams](../../../CLAUDE.md#wiring--seams-enforced--this-is-where-the-real-bugs-are),
which is where this project's shipped bugs have actually lived.

It has earned its place. On T-128 the panel found a contract guard that checked
a key's *presence* rather than its value (so `additionalProperties: true` passed
the rule forbidding it), and a schema tightening that would have broken a live
response because the read boundary did not enforce the cap the schema had just
started requiring. Both were green under every other gate.

## Running it

**One message, one `Agent` call per seat, so they run concurrently.** Sequential
calls are a different and much slower thing. How many seats is a judgement about
the diff — a two-file docs change does not want the mobile and UI lanes — but
Security and Architecture are always two of them.

| Dimension | Agent type | Reads for |
| --- | --- | --- |
| Mobile | `Mobile App Builder` | crash/undefined risk on device, contract drift, i18n parity, native rebuild needed |
| Backend | `Backend Architect` | correctness and edge cases, N+1, validation reachability, CI gates that cannot fail |
| **Security** (mandatory) | `Senior SecOps Engineer` | authz/IDOR, mass assignment, injection, SSRF, data exposure, secrets, and anything a test environment can reach that production shouldn't |
| **Architecture** (mandatory) | `Software Architect` | boundaries and contracts, a seam duplicated or bypassed, god objects, migration and rollout safety, blast radius beyond the files the diff touches |
| UX | `UX Architect` | states (loading/empty/partial/error), truthfulness of claims, reachability, a11y |
| UI | `UI Designer` | design-token adherence, component duplication, dark mode, overflow |
| Tests | `Code Reviewer` | tests that pass regardless, missing failure paths, mocks that hide crashes |

Give every one of them the same frame:

- **READ-ONLY. Do not edit, write, or commit.** Findings only — you apply them.
- Scope is `git diff main...HEAD`; name the files in their lane explicitly.
- Read `CLAUDE.md` first, especially **Wiring & seams** and **Testing standards**.
- Every finding needs a severity (🔴 blocking / 🟡 should-fix / 🔵 nit), a
  `file:line`, and a **concrete failure scenario** — inputs or state leading to
  a wrong result. *"Reject any finding you cannot make concrete."*
- **A clean dimension gets one line, not padding.** Without this they invent
  work to look useful.
- Cap the reply (~600–700 words) and forbid file dumps.
- **Tell every seat to check each new COMMENT against every file it rests on** —
  not only the one it cites. A claim about how other code behaves usually
  names one file and depends on several; checking the cited line and stopping
  is how a false premise survives its own review. A
  comment that asserts how other code behaves is the one claim no gate can test,
  and this project keeps producing them: T-168 shipped one whose premise was
  false, and T-158 produced five in a single branch — including one crediting
  `scripts/deploy.sh` with rollback protection it does not have, and one calling
  the map's 90°-span bbox a bound comparable to a 50km radius. They are cheap to
  catch (open the named file) and, once wrong, they are what stops the next
  reader from looking.

Adjust the lanes to the diff: a backend-only branch does not need the UI seat,
and a diff touching payments or auth deserves a seat this table does not list.

**The Security and Architecture seats are the exception — they are filled on
every diff, without exception** (owner instruction, 2026-09-03). They are the
two lanes whose findings are least likely to be visible in the diff itself: a
security hole is usually an absence, and an architecture problem is usually
somewhere the diff does not touch. Fitting lanes to the diff means dropping
*mobile* from a backend branch, never dropping these two because the change
"looks small". On a mobile-only diff the architecture seat reads the mobile
architecture; on a docs-only diff the seat is still FILLED — it may report in
one line, but it is not skipped. Under `.claude/`, docs are the guard.

## After they report

1. **Verify before fixing.** Agents are confidently wrong sometimes, and a
   finding contradicted by the code costs more to apply than to check. Read the
   cited lines.
2. **Fix every 🔴 and 🟡**, or get the owner to waive one explicitly. A finding
   that is a product decision rather than a defect (a deliberate removal, a
   design tradeoff) is the owner's call — surface it, do not quietly implement
   your own answer.
3. **Batch the fixes into ONE commit before re-reviewing.** Both receipts are
   keyed to HEAD and die on the next commit — deliberately, since a fix is
   exactly the code nobody has reviewed. So every fix commit costs a full round:
   T-158 ran five. Collect every seat's findings, apply them together, re-seat
   the panel once. Narrow the lanes for a later round only when that round
   changed neither code nor any comment asserting how other code behaves — the
   defect above is prose, and it lives in whichever lane owns the code it lies
   about. **Narrowing never reaches Security or Architecture**, prose-only
   rounds included: under `.claude/` the prose IS the guard, so "no code
   changed" is exactly the round in which a weakened escape hatch would ship
   looking audited. The receipt records no seats and cannot notice. Otherwise the seats that code belongs to stay, tests included: a fix
   is the least-reviewed thing on the branch, and no receipt records which seats
   you filled.

   (The two receipts: this skill's `record-receipt.sh`, enforced by
   `.claude/hooks/guard-pr-audit.py`; and `/coderabbit`'s, written by
   `~/.claude/skills/coderabbit/scripts/approve.sh` and enforced by `pr-gate.sh`
   beside it. Those last two are user-level — grepping this repo for them finds
   nothing.)
4. **Prove each fix bites.** Mutate the fix, run the test, confirm it fails,
   restore. A guard nobody has watched fail is worth as little as the bug it was
   written to catch. **Restore with absolute paths** — a `cd` inside a multi-step
   script has already left mutants in the tree here.
5. **Re-run the gates** (`.claude/skills/gates/run-gates.sh`); the fixes are code.
6. **Commit**, then record the receipt — in that order, since it covers the tree:

```bash
.claude/skills/audit-agency/record-receipt.sh findings-fixed "3 🟡: contract guard, hours reporting path, review cap"
```

Use `clean` as the verdict when nothing needed fixing.

## Notes

- The receipt dies on the next commit, amend, rebase, or uncommitted edit — it
  hashes the tree's CONTENT, not `git status` output, because status prints
  paths and codes, so a second edit to an already-modified file left the old
  hash intact and the receipt certified code the audit never saw.
- **The gate is a process check, not a security boundary.** A subshell, an
  alias, or `$(echo pu)sh` gets through, and that is accepted; the threat model
  is a busy agent taking a shortcut, not someone attacking their own repo.
- `REELMAP_SKIP_AUDIT=1` exists for a push the audit cannot apply to. Reaching
  for it because the audit is slow is how the check becomes decoration.
- Findings converging from two independent lanes are the ones to trust most; a
  lone finding no other reviewer saw deserves the hardest look before you act.
