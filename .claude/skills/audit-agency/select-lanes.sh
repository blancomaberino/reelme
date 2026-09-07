#!/usr/bin/env bash
# select-lanes.sh — which agency seats THIS diff needs, decided from the files
# it touches rather than from habit.
#
# Usage:  select-lanes.sh [BASE]          (default: main, then origin/main)
# Output: a LANES block (one agent per line, with the reason) or "LANES: none",
#         followed by the exact record-receipt.sh invocation to use.
# Exit:   0 always — advisory; the hook (guard-pr-audit.py) only checks that a
#         receipt exists for HEAD + tree, never which seats were filled.
#
# The diff considered is the WORKING TREE against the merge-base with BASE —
# committed, staged, unstaged and untracked alike — because that is exactly
# what the receipt certifies.
#
# Rules (owner decision, 2026-09-07):
#   - Pure documentation (*.md, docs/) outside .claude/ and CLAUDE.md needs no
#     panel. Prose cannot introduce the defects the panel looks for.
#   - .claude/**, CLAUDE.md, .github/**, scripts/** are the guard: Security +
#     Architecture always, because a weakened hook or escape hatch ships looking
#     audited otherwise.
#   - Any other change: Security + Architecture (mandatory), plus the lanes the
#     paths select. A lane is added by a path; nothing is added "to be safe".
set -uo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "LANES: none — not a git repo"; exit 0; }
cd "$ROOT" || exit 0
AGENTS_DIR="$ROOT/.claude/agents"

BASE="${1:-main}"
git rev-parse --verify -q "$BASE" >/dev/null 2>&1 || BASE=origin/main
MB="$(git merge-base "$BASE" HEAD 2>/dev/null)" || MB="$BASE"

FILES="$( { git diff --name-only "$MB" 2>/dev/null; git ls-files --others --exclude-standard 2>/dev/null; } \
          | grep -v '^\.claude/state/' | sort -u )"

if [ -z "$FILES" ]; then
  echo "LANES: none — no changes against $BASE."
  echo "RECEIPT: nothing to audit."
  exit 0
fi

has()   { printf '%s\n' "$FILES" | grep -qE  "$1"; }
hasi()  { printf '%s\n' "$FILES" | grep -qiE "$1"; }
count() { printf '%s\n' "$FILES" | grep -c .; }

GUARD_RE='^(\.claude/|CLAUDE\.md$|\.github/|scripts/)'
DOCS_RE='(\.md$|^docs/)'

# --- docs only ---------------------------------------------------------------
# Every file is documentation AND none of it is the guard.
if ! printf '%s\n' "$FILES" | grep -vE "$DOCS_RE" | grep -q . && ! has "$GUARD_RE"; then
  echo "LANES: none — documentation only ($(count) file(s): *.md / docs/, none under .claude/ or CLAUDE.md)."
  echo "RECEIPT: .claude/skills/audit-agency/record-receipt.sh docs-only"
  exit 0
fi

# --- seats -------------------------------------------------------------------
declare -a NAME REASON
want() { NAME+=("$1"); REASON+=("$2"); }

want "Senior SecOps Engineer" "security — mandatory on every non-docs diff"
want "Software Architect"     "architecture — mandatory on every non-docs diff"

has "$GUARD_RE" && want "Code Reviewer" "guard files changed (.claude/, CLAUDE.md, .github/, scripts/) — a check that cannot fail is the bug"

if has '^apps/api/'; then
  want "Backend Architect" "apps/api changed — correctness, N+1, validation reachability"
  has '^apps/api/(app|routes|database)/' && want "Code Reviewer" "API code changed — tests that pass regardless, missing failure paths"
fi

has 'database/migrations/|\.sql$' && want "Database Optimizer" "migration / SQL changed — schema, index, rollout safety"

if has '^apps/mobile/'; then
  want "Mobile App Builder" "apps/mobile changed — device crash risk, native rebuild, i18n parity"
  has '^apps/mobile/(app|src/components|src/screens|src/features)/.*\.tsx$' && {
    want "UX Architect" "a screen or component changed — states, reachability, a11y"
    want "UI Designer"  "a screen or component changed — tokens, duplication, dark mode, overflow"
  }
  has '^apps/mobile/(app\.config|package\.json)' && want "native-rebuild-checker" "app.config / dependencies changed — JS-only or full rebuild?"
fi

has '^packages/contracts/|^apps/api/app/Http/Resources/|^apps/mobile/src/api/' \
  && want "contract-consistency-reviewer" "a payload shape changed — Resource, JSON Schema and mobile TS must agree"

has '^apps/api/(app/Filament|resources/views)/' && want "UX Architect" "admin / web surface changed — states, truthfulness, a11y"

# Second security reading only when the CONTENT is sensitive (non-docs lines).
SENSITIVE_RE='auth|login|session|token|secret|credential|password|permission|polic|authoriz|payment|billing|ledger|payout|redeem|webhook|mercado|stripe'
sensitive=""
hasi "$SENSITIVE_RE" && sensitive=1
if [ -z "$sensitive" ]; then
  set +o pipefail  # grep -q SIGPIPEs its producer; under pipefail a big diff read as "no match"
  git diff "$MB" -- . ':(exclude)*.md' 2>/dev/null | grep -E '^[+-]' | grep -qiE "$SENSITIVE_RE" && sensitive=1
  # Untracked files are not in `git diff`; read them directly (non-markdown only).
  git ls-files --others --exclude-standard 2>/dev/null | grep -vE "$DOCS_RE" | grep -v '^\.claude/state/' \
    | xargs -I{} grep -liE "$SENSITIVE_RE" {} 2>/dev/null | grep -q . && sensitive=1
  set -o pipefail
fi
[ -n "$sensitive" ] && want "Application Security Engineer" "auth / money / secrets in the change — a second, code-level security reading"
hasi 'payment|billing|ledger|payout|mercado|stripe' && want "Payments & Billing Engineer" "money moves — idempotency, reconciliation, webhooks"

# --- emit, deduplicated, only agents that exist ---------------------------------
exists() { [ -d "$AGENTS_DIR" ] && grep -qxF "name: $1" "$AGENTS_DIR"/*.md 2>/dev/null; }

declare -a SEEN
echo "LANES ($(count) changed file(s) vs $BASE):"
missing=0
for i in "${!NAME[@]}"; do
  n="${NAME[$i]}"
  dup=""
  for s in "${SEEN[@]:-}"; do [ "$s" = "$n" ] && dup=1; done
  [ -n "$dup" ] && continue
  SEEN+=("$n")
  if exists "$n"; then
    printf '  - %-32s — %s\n' "$n" "${REASON[$i]}"
  else
    printf '  - %-32s — %s  (NOT INSTALLED under .claude/agents — spawn general-purpose and adopt the persona)\n' "$n" "${REASON[$i]}"
    missing=$((missing + 1))
  fi
done
echo
echo "Launch every seat in ONE message (one Agent call each) over the same diff."
echo "RECEIPT: .claude/skills/audit-agency/record-receipt.sh <clean|findings-fixed> \"<note>\"   (after the fix commit)"
exit 0
