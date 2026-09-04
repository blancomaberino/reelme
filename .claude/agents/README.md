# Agency agents

Reviewer and specialist personas. `audit-agency` seats them over a branch, and
`/coderabbit`'s panel selectors pick from whatever is installed here.

## CLAUDE.md outranks every file in this directory

A persona is a **system prompt**. Whatever it says about how it works becomes
instructions an agent follows, and several of these were written for a generic
project rather than this one. So, explicitly:

- **No agent commits, pushes, merges, or opens a PR on its own.** Golden rule #1
  is not negotiable by a persona that describes itself as autonomous —
  `agents-orchestrator.md` says it "handles errors and bottlenecks without manual
  intervention" and runs from "a single initial command"; here it does neither.
- **No agent skips the gates, the audit, or a review**, whatever its own
  description implies about speed or autonomy.
- **Review agents are read-only.** They report findings; the caller applies them.
  A finding is a lead to verify against the code, not an instruction to obey —
  they are confidently wrong often enough that this matters.
- Where a persona and `CLAUDE.md` disagree, `CLAUDE.md` wins and the persona is
  the thing to correct.

This is stated once here rather than patched into 90-odd files, because the next
persona added will have the same property.

## What is tracked, and what is not

Tracked: the agents the rules NAME as mandatory seats (`Senior SecOps Engineer`,
`Software Architect`), the ones the panel selectors can choose, and the ones this
stack can plausibly reach — Laravel, Expo/React Native, Postgres/PostGIS,
Meilisearch, the AI extraction pipeline, payments, privacy, i18n, release and
infrastructure.

Not tracked: personas for stacks nothing here routes to — WeChat, Feishu,
GaussDB, Solidity, blockchain audit, Drupal, WordPress, USWDS, Section 508,
embedded firmware, IoT fleet, networking, OrgScript, WebAssembly, desktop apps,
Rust refactoring, video streaming. They may still exist on a given machine; they
are simply not this repo's business, and every one of them is permanent grep
noise and another node in the graphify index.

Two files here are **not** personas and predate the set:
`contract-consistency-reviewer.md` and `native-rebuild-checker.md` are
project-specific reviewers that encode local knowledge a generic prompt lacks.
Prefer them over a generic agent for what they cover.

## Adding one

Add it when a rule, a skill or a selector will actually name it. If you add a
persona because it might be useful someday, it will be read by `grep`, indexed by
graphify, and seated by nobody.
