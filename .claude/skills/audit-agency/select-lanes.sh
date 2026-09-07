#!/usr/bin/env bash
# select-lanes.sh — which agency seats THIS diff needs, decided from the files
# it touches rather than from habit.
#
# Usage:  select-lanes.sh [BASE]          (default: origin/main, then main)
# Output: a LANES block (one agent per line, with the reason), or
#         "LANES: none — documentation only …", or "LANES: unknown …" (exit 1).
#         Then the exact record-receipt.sh invocation to use.
# Exit:   0 when a decision was made; 1 when it could not be (no base ref).
#         Advisory either way — the hook (guard-pr-audit.py) only checks that a
#         receipt exists for HEAD + tree, never which seats were filled.
#
# The diff considered is the WORKING TREE against the merge-base with BASE —
# committed, staged, unstaged and untracked alike — because that is exactly
# what the receipt certifies.
#
# Rules (owner decision, 2026-09-07):
#   - Documentation is a .md file in an allowlisted place: docs/, apps/*/docs/,
#     a README.md, or a top-level *.md. BOTH conditions — a .php under docs/ is
#     a Filament page and a .tsx under app/docs/ is an Expo route, and a .md
#     under resources/prompts/ is an LLM system prompt the API executes.
#   - The guard — a .claude/, .github/ or scripts/ directory AT ANY DEPTH, any
#     CLAUDE.md / AGENTS.md, .mcp.json — always seats Security + Architecture
#     (+ Code Reviewer): a weakened hook or escape hatch would otherwise ship
#     looking audited. Anchored on a path segment, not the root: this harness
#     auto-loads docs/.claude/skills/* and apps/api/.claude/* just the same.
#   - Any other change: Security + Architecture (mandatory), plus the lanes the
#     paths select. A lane is added by a path; nothing is added "to be safe".
#     Files no rule knows are listed as UNMATCHED so the gap is visible.
set -uo pipefail

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "LANES: unknown — not a git repo"; exit 1; }
cd "$ROOT" || exit 1
AGENTS_DIR="$ROOT/.claude/agents"

# quotePath=off: with it on, a non-ASCII path arrives as "\"se\\303\\261al.php\""
# and the leading quote defeats every ^-anchored rule below.
g() { git -c core.quotePath=off "$@"; }

BASE="${1:-}"
if [ -z "$BASE" ]; then
  for ref in origin/main main; do
    git rev-parse --verify -q "$ref" >/dev/null 2>&1 && { BASE="$ref"; break; }
  done
fi
MB="$(git merge-base "${BASE:-HEAD}" HEAD 2>/dev/null)"
if [ -z "$BASE" ] || [ -z "$MB" ]; then
  # Fail CLOSED: an empty file list would read as "nothing changed" and a
  # docs-only receipt would be accepted on a code diff.
  echo "LANES: unknown — no base ref (${BASE:-origin/main, main}) resolves, so the diff cannot be read."
  echo "RECEIPT: none — fetch main first."
  exit 1
fi

FILES="$( { g diff --name-only "$MB" 2>/dev/null; g ls-files --others --exclude-standard 2>/dev/null; } \
          | grep -v '^\.claude/state/' | sort -u )"

if [ -z "$FILES" ]; then
  echo "LANES: none — no changes against $BASE."
  echo "RECEIPT: nothing to audit."
  exit 0
fi

has()   { printf '%s\n' "$FILES" | grep -qE  "$1"; }
hasi()  { printf '%s\n' "$FILES" | grep -qiE "$1"; }
only()  { ! printf '%s\n' "$FILES" | grep -vE "$1" | grep -q .; }
count() { printf '%s\n' "$FILES" | grep -c .; }

GUARD_RE='(^|/)(\.claude|\.github|scripts)/|(^|/)(CLAUDE|AGENTS)(\.local)?\.md$|(^|/)\.mcp\.json$'
DOCS_RE='^([^/]+\.md|docs/.*\.md|apps/[^/]+/docs/.*\.md|(.*/)?README\.md)$'
KNOWN_RE="$GUARD_RE|$DOCS_RE|"'^(apps/api|apps/mobile|packages/contracts)/|^(package\.json|package-lock\.json|\.gitignore)$'

# --- docs only ---------------------------------------------------------------
if only "$DOCS_RE" && ! has "$GUARD_RE"; then
  echo "LANES: none — documentation only ($(count) file(s): docs/, README.md, top-level *.md; nothing under .claude/, no CLAUDE.md)."
  echo "RECEIPT: .claude/skills/audit-agency/record-receipt.sh docs-only"
  exit 0
fi

# --- seats -------------------------------------------------------------------
declare -a NAME REASON
SEEN=$'\n'
want() {  # first call wins; a seat selected by two rules is listed once
  case "$SEEN" in *$'\n'"$1"$'\n'*) return ;; esac
  SEEN="$SEEN$1"$'\n'
  NAME+=("$1"); REASON+=("$2")
}

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
  if has '^apps/mobile/(app|src/components|src/screens|src/features)/.*\.tsx$'; then
    want "UX Architect" "a screen or component changed — states, reachability, a11y"
    want "UI Designer"  "a screen or component changed — tokens, duplication, dark mode, overflow"
  fi
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
  # Only DOCS paths are exempt — a committed resources/prompts/*.md is code.
  # NUL-separated names and --literal-pathspecs: a file named `:!*` is pathspec
  # magic that would otherwise empty the whole scan.
  g diff --name-only -z "$MB" 2>/dev/null | grep -zvE "$DOCS_RE" \
    | xargs -0r git --literal-pathspecs -c core.quotePath=off diff "$MB" -- 2>/dev/null | grep -E '^[+-]' | grep -qiE "$SENSITIVE_RE" && sensitive=1
  # Untracked files are not in `git diff`; read them directly. NUL-separated:
  # a quote or non-ASCII byte in a name made xargs drop the file, or abort the
  # whole scan, so a new file could carry a password check past this line.
  g ls-files -z --others --exclude-standard 2>/dev/null | grep -zvE "$DOCS_RE" | grep -zv '^\.claude/state/' \
    | xargs -0r grep -liE "$SENSITIVE_RE" -- 2>/dev/null | grep -q . && sensitive=1  # -- : a name starting with - is not an option
  set -o pipefail
fi
[ -n "$sensitive" ] && want "Application Security Engineer" "auth / money / secrets in the change — a second, code-level security reading"
hasi 'payment|billing|ledger|payout|mercado|stripe' && want "Payments & Billing Engineer" "money moves — idempotency, reconciliation, webhooks"

# --- emit --------------------------------------------------------------------
exists() { [ -d "$AGENTS_DIR" ] && grep -qxF "name: $1" "$AGENTS_DIR"/*.md 2>/dev/null; }

echo "LANES ($(count) changed file(s) vs $BASE):"
for i in "${!NAME[@]}"; do
  n="${NAME[$i]}"
  if exists "$n"; then
    printf '  - %-32s — %s\n' "$n" "${REASON[$i]}"
  else
    printf '  - %-32s — %s  (NOT INSTALLED under .claude/agents — spawn general-purpose and adopt the persona)\n' "$n" "${REASON[$i]}"
  fi
done

unmatched="$(printf '%s\n' "$FILES" | grep -vE "$KNOWN_RE")"
if [ -n "$unmatched" ]; then
  echo
  echo "UNMATCHED — no rule knows these paths; they get only the mandatory seats. Add a rule (with a test) if this is a new area:"
  printf '%s\n' "$unmatched" | sed 's/^/  /'
fi
echo
echo "Launch every seat in ONE message (one Agent call each) over the same diff."
echo "RECEIPT: .claude/skills/audit-agency/record-receipt.sh <clean|findings-fixed> \"<note>\"   (after the fix commit)"
exit 0
