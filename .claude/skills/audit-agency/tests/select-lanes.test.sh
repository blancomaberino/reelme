#!/usr/bin/env bash
# select-lanes.sh must seat the right reviewers for the right diff — and, just as
# importantly, seat NOBODY for a docs-only diff and refuse a docs-only receipt on
# a code diff. Each case builds a scratch repo so the assertions are about the
# selector, not about this checkout's branch.
# shellcheck disable=SC2015,SC2016  # ok() never fails, so A && ok || bad is exact; the PHP fixture is literal
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL="$(cd "$HERE/.." && pwd)"
ROOT="$(cd "$SKILL/../../.." && pwd)"

pass=0 fail=0
ok()   { pass=$((pass + 1)); printf '  PASS  %s\n' "$1"; }
bad()  { fail=$((fail + 1)); printf '  FAIL  %s\n        %s\n' "$1" "$2"; }

# scratch <name> — a repo on branch `work` off `main`, carrying the selector,
# the receipt script, the hook it imports, and the agent personas it resolves.
scratch() {
  local d
  d="$(mktemp -d)"
  git -C "$d" init -q -b main
  git -C "$d" config user.email t@t; git -C "$d" config user.name t
  mkdir -p "$d/.claude/skills/audit-agency" "$d/.claude/hooks" "$d/.claude/agents"
  cp "$SKILL/select-lanes.sh" "$SKILL/record-receipt.sh" "$d/.claude/skills/audit-agency/"
  cp "$ROOT/.claude/hooks/guard-pr-audit.py" "$d/.claude/hooks/"
  for n in "Senior SecOps Engineer" "Software Architect" "Backend Architect" "Code Reviewer" \
           "Database Optimizer" "Mobile App Builder" "UX Architect" "UI Designer" \
           "Application Security Engineer" "contract-consistency-reviewer" "native-rebuild-checker"; do
    printf -- '---\nname: %s\n---\n' "$n" > "$d/.claude/agents/$(echo "$n" | tr ' ' '-').md"
  done
  printf '.claude/state/\n' > "$d/.gitignore"
  git -C "$d" add -A && git -C "$d" commit -qm base
  git -C "$d" checkout -q -b work
  echo "$d"
}

touchf() { mkdir -p "$(dirname "$1")"; printf '%s\n' "${2:-x}" > "$1"; }
lanes()  { (cd "$1" && bash .claude/skills/audit-agency/select-lanes.sh main); }
seated() { printf '%s' "$1" | grep -qF -- "- $2"; }

echo "select-lanes.sh"

# 1. docs only → nobody, receipt docs-only
d="$(scratch)"; touchf "$d/docs/thing.md"; touchf "$d/README.md"
out="$(lanes "$d")"
if printf '%s' "$out" | grep -q '^LANES: none' && printf '%s' "$out" | grep -q 'record-receipt.sh docs-only'; then
  ok "docs-only diff seats nobody"; else bad "docs-only diff seats nobody" "$out"; fi
(cd "$d" && git add -A && git commit -qm docs && bash .claude/skills/audit-agency/record-receipt.sh docs-only >/dev/null 2>&1) \
  && ok "docs-only receipt accepted on a docs diff" || bad "docs-only receipt accepted on a docs diff" "record-receipt refused"

# 2. CLAUDE.md is the guard → Security + Architecture, never "none"
d="$(scratch)"; touchf "$d/CLAUDE.md"
out="$(lanes "$d")"
if seated "$out" "Senior SecOps Engineer" && seated "$out" "Software Architect" && ! printf '%s' "$out" | grep -q '^LANES: none'; then
  ok "CLAUDE.md alone still seats Security + Architecture"; else bad "CLAUDE.md alone still seats Security + Architecture" "$out"; fi
(cd "$d" && git add -A && git commit -qm guard && bash .claude/skills/audit-agency/record-receipt.sh docs-only >/dev/null 2>&1) \
  && bad "docs-only receipt REFUSED on a guard diff" "was accepted" || ok "docs-only receipt REFUSED on a guard diff"

# 3. docs + one PHP file → not docs-only; Backend seated
d="$(scratch)"; touchf "$d/docs/a.md"; touchf "$d/apps/api/app/Models/Place.php"
out="$(lanes "$d")"
if seated "$out" "Backend Architect" && seated "$out" "Code Reviewer" && ! printf '%s' "$out" | grep -q '^LANES: none'; then
  ok "one PHP file among docs brings the normal rules back"; else bad "one PHP file among docs brings the normal rules back" "$out"; fi
if ! seated "$out" "Mobile App Builder" && ! seated "$out" "UI Designer"; then
  ok "an API-only diff does not seat mobile or UI"; else bad "an API-only diff does not seat mobile or UI" "$out"; fi

# 4. migration → Database Optimizer
d="$(scratch)"; touchf "$d/apps/api/database/migrations/2026_01_01_000000_x.php"
out="$(lanes "$d")"
seated "$out" "Database Optimizer" && ok "a migration seats Database Optimizer" || bad "a migration seats Database Optimizer" "$out"

# 5. mobile screen → Mobile + UX + UI; a mobile lib file → Mobile only
d="$(scratch)"; touchf "$d/apps/mobile/app/(main)/places.tsx"
out="$(lanes "$d")"
if seated "$out" "Mobile App Builder" && seated "$out" "UX Architect" && seated "$out" "UI Designer"; then
  ok "a mobile screen seats Mobile + UX + UI"; else bad "a mobile screen seats Mobile + UX + UI" "$out"; fi
d="$(scratch)"; touchf "$d/apps/mobile/src/lib/format.ts"
out="$(lanes "$d")"
if seated "$out" "Mobile App Builder" && ! seated "$out" "UI Designer"; then
  ok "a mobile lib file seats Mobile but not UI"; else bad "a mobile lib file seats Mobile but not UI" "$out"; fi

# 6. contract ends → contract-consistency-reviewer
d="$(scratch)"; touchf "$d/apps/api/app/Http/Resources/PlaceResource.php"
out="$(lanes "$d")"
seated "$out" "contract-consistency-reviewer" && ok "a Resource change seats the contract reviewer" || bad "a Resource change seats the contract reviewer" "$out"

# 7. sensitive CONTENT (not path) → Application Security Engineer; and the same word in prose does not
d="$(scratch)"; touchf "$d/apps/api/app/Services/Thing.php" 'if ($user->password === $x) {}'
out="$(lanes "$d")"
seated "$out" "Application Security Engineer" && ok "sensitive content seats AppSec" || bad "sensitive content seats AppSec" "$out"
d="$(scratch)"; touchf "$d/apps/api/app/Services/Plain.php" 'return 1;'; touchf "$d/docs/x.md" 'the password rule'
out="$(lanes "$d")"
! seated "$out" "Application Security Engineer" && ok "sensitive words in prose alone do not seat AppSec" || bad "sensitive words in prose alone do not seat AppSec" "$out"

# 8. uncommitted + untracked files count (the receipt covers the tree)
d="$(scratch)"; touchf "$d/apps/api/database/migrations/2026_01_01_000001_y.php"
out="$(lanes "$d")"
seated "$out" "Database Optimizer" && ok "untracked files are part of the diff" || bad "untracked files are part of the diff" "$out"

# 9. every seat is emitted once
d="$(scratch)"; touchf "$d/apps/api/app/Models/A.php"; touchf "$d/.claude/hooks/x.sh"
out="$(lanes "$d")"
n="$(printf '%s\n' "$out" | grep -c -- '- Code Reviewer')"
[ "$n" -eq 1 ] && ok "a seat selected by two rules is listed once" || bad "a seat selected by two rules is listed once" "listed $n times"

# 10. the receipt records the required lanes
d="$(scratch)"; touchf "$d/apps/api/app/Models/A.php"
(cd "$d" && git add -A && git commit -qm code && bash .claude/skills/audit-agency/record-receipt.sh clean >/dev/null 2>&1)
if grep -q '"Senior SecOps Engineer"' "$d/.claude/state/audit-receipt.json" && grep -q '"Backend Architect"' "$d/.claude/state/audit-receipt.json"; then
  ok "receipt records the required lanes"; else bad "receipt records the required lanes" "$(cat "$d/.claude/state/audit-receipt.json")"; fi

printf '\n%d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
