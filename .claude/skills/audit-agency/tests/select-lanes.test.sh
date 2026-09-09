#!/usr/bin/env bash
# select-lanes.sh must seat the right reviewers for the right diff — and, just as
# importantly, seat NOBODY for a docs-only diff, refuse a docs-only receipt on
# anything else, and fail CLOSED when it cannot read the diff. Each case builds
# a scratch repo so the assertions are about the selector, not this checkout.
#
# The table cases are one row per RULE in select-lanes.sh: delete a rule and
# its row goes red (verified by mutation when the table was written).
# shellcheck disable=SC2015,SC2016  # ok() never fails, so A && ok || bad is exact; PHP fixtures are literal
set -uo pipefail
# Never let the session's project dir redirect a receipt into the real repo.
unset CLAUDE_PROJECT_DIR
SCRATCH_DIRS=()
trap 'rm -rf "${SCRATCH_DIRS[@]:-}"' EXIT

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILL="$(cd "$HERE/.." && pwd)"
ROOT="$(cd "$SKILL/../../.." && pwd)"

pass=0 fail=0
ok()   { pass=$((pass + 1)); printf '  PASS  %s\n' "$1"; }
bad()  { fail=$((fail + 1)); printf '  FAIL  %s\n        %s\n' "$1" "$2"; }

AGENTS="Senior SecOps Engineer|Software Architect|Backend Architect|Code Reviewer|Database Optimizer|Mobile App Builder|UX Architect|UI Designer|Application Security Engineer|contract-consistency-reviewer|native-rebuild-checker|Payments & Billing Engineer"

# scratch — a repo on branch `work` off `main`, carrying the selector, the
# receipt script, the hook it imports, and the agent personas it resolves.
scratch() {
  local d
  d="$(mktemp -d)"
  SCRATCH_DIRS+=("$d")
  git -C "$d" init -q -b main
  git -C "$d" config user.email t@t; git -C "$d" config user.name t
  mkdir -p "$d/.claude/skills/audit-agency" "$d/.claude/hooks" "$d/.claude/agents"
  cp "$SKILL/select-lanes.sh" "$SKILL/record-receipt.sh" "$SKILL/check-grounding.py" \
     "$SKILL/run-grounding.sh" "$d/.claude/skills/audit-agency/"
  cp "$ROOT/.claude/hooks/guard-pr-audit.py" "$d/.claude/hooks/"
  local IFS='|'
  for n in $AGENTS; do
    printf -- '---\nname: %s\n---\n' "$n" > "$d/.claude/agents/$(echo "$n" | tr ' &' '-_').md"
  done
  printf '.claude/state/\n' > "$d/.gitignore"
  git -C "$d" add -A && git -C "$d" commit -qm base
  git -C "$d" checkout -q -b work
  echo "$d"
}

touchf()  { mkdir -p "$(dirname "$1")"; printf '%s\n' "${2:-x}" > "$1"; }
lanes()   { (cd "$1" && bash .claude/skills/audit-agency/select-lanes.sh main); }
seated()  { printf '%s' "$1" | grep -qF -- "- $2"; }
# record-receipt.sh now refuses without a grounding marker for the current tree
# (the seats are one axis of /coderabbit, not the review). These cases are about
# LANE SELECTION, not about grounding — grounding.test.sh owns that — so they
# stub the marker with the documented skip rather than install the user-level
# script. The skip is recorded in the receipt either way, which is the point.
grounded() { (cd "$1" && REELMAP_SKIP_GROUNDING=1 bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1); }
receipt() { (cd "$1" && git add -A && git commit -qm c) >/dev/null 2>&1; grounded "$1"; (cd "$1" && { bash .claude/skills/audit-agency/record-receipt.sh "$2" >/dev/null; } 2>&1); }

echo "select-lanes.sh"

# ---------------------------------------------------------------- table: one row per rule
# path | fixture content | must seat (|-separated) | must NOT seat
while IFS='|' read -r path content must mustnot; do
  [ -n "$path" ] || continue
  d="$(scratch)"; touchf "$d/$path" "$content"
  out="$(lanes "$d")"
  err=""
  # `${arr[@]:-}` — bash 3.2 treats an empty array as unbound under set -u
  IFS=';' read -ra m <<< "$must";     for s in "${m[@]:-}";  do [ -n "$s" ] && ! seated "$out" "$s" && err="$err missing:$s"; done
  IFS=';' read -ra nn <<< "$mustnot"; for s in "${nn[@]:-}"; do [ -n "$s" ] && seated "$out" "$s"   && err="$err unexpected:$s"; done
  if [ -z "$err" ]; then ok "$path → ${must//;/, }${mustnot:+ (not ${mustnot//;/, })}"; else bad "$path" "$err"; fi
done <<'ROWS'
CLAUDE.md|x|Senior SecOps Engineer;Software Architect;Code Reviewer|Backend Architect
apps/api/CLAUDE.md|x|Senior SecOps Engineer;Software Architect;Code Reviewer|
docs/AGENTS.md|x|Senior SecOps Engineer;Software Architect;Code Reviewer|
docs/.claude/skills/x/SKILL.md|x|Senior SecOps Engineer;Software Architect;Code Reviewer|
apps/api/.claude/hooks/x.sh|x|Code Reviewer|
.mcp.json|x|Code Reviewer|
.claude/hooks/x.sh|x|Code Reviewer|Backend Architect
.github/workflows/ci.yml|x|Code Reviewer|
scripts/deploy.sh|x|Code Reviewer|
apps/api/resources/prompts/extraction.system.md|x|Senior SecOps Engineer;Software Architect;Backend Architect|
apps/api/app/Filament/Pages/docs/Evil.php|x|Backend Architect;UX Architect|
apps/mobile/app/docs/index.tsx|x|Mobile App Builder;UX Architect|
apps/api/app/Models/Place.php|x|Backend Architect;Code Reviewer|Mobile App Builder;UI Designer;Application Security Engineer
apps/api/tests/Feature/X.php|x|Backend Architect|Code Reviewer
apps/api/database/migrations/2026_01_01_000000_x.php|x|Database Optimizer|
apps/api/app/Http/Resources/PlaceResource.php|x|contract-consistency-reviewer|
packages/contracts/schemas/place.json|x|contract-consistency-reviewer|
apps/mobile/src/api/places.ts|x|contract-consistency-reviewer;Mobile App Builder|
apps/mobile/app/(main)/places.tsx|x|Mobile App Builder;UX Architect;UI Designer|Backend Architect
apps/mobile/src/lib/format.ts|x|Mobile App Builder|UI Designer;UX Architect
apps/mobile/app.config.ts|x|native-rebuild-checker|
apps/api/app/Filament/Resources/PlaceResource.php|x|UX Architect|UI Designer
apps/api/app/Services/Thing.php|if ($user->password === $x) {}|Application Security Engineer|Payments & Billing Engineer
apps/api/app/Services/PayoutLedger.php|x|Application Security Engineer;Payments & Billing Engineer|
ROWS

# ---------------------------------------------------------------- docs-only
d="$(scratch)"; touchf "$d/docs/thing.md"; touchf "$d/README.md"; touchf "$d/apps/api/README.md"; touchf "$d/apps/api/docs/moderation.md"
out="$(lanes "$d")"
if printf '%s' "$out" | grep -q '^LANES: none — documentation only' && printf '%s' "$out" | grep -q 'record-receipt.sh docs-only'; then
  ok "docs/, README.md, nested docs/ and top-level .md → nobody"; else bad "docs-only diff seats nobody" "$out"; fi
receipt "$d" docs-only >/dev/null && ok "docs-only receipt accepted on a docs diff" || bad "docs-only receipt accepted on a docs diff" "refused"

d="$(scratch)"; touchf "$d/docs/a.md"; touchf "$d/apps/api/app/Models/Place.php"
out="$(lanes "$d")"
! printf '%s' "$out" | grep -q '^LANES: none' && ok "one PHP file among docs brings the normal rules back" || bad "one PHP file among docs" "$out"

d="$(scratch)"; touchf "$d/CLAUDE.md"
e="$(receipt "$d" docs-only)"; rc=$?
[ $rc -ne 0 ] && printf '%s' "$e" | grep -q '^refused:' && ok "docs-only receipt REFUSED on a guard diff (says refused)" || bad "docs-only receipt REFUSED on a guard diff" "rc=$rc $e"

d="$(scratch)"; touchf "$d/apps/api/resources/prompts/x.md"
receipt "$d" docs-only >/dev/null 2>&1 && bad "docs-only receipt REFUSED on a .md under apps/" "accepted" || ok "docs-only receipt REFUSED on a .md under apps/"

# ---------------------------------------------------------------- sensitive content, every source
d="$(scratch)"; touchf "$d/apps/api/app/Services/Plain.php" 'return 1;'; touchf "$d/docs/x.md" 'the password rule'
out="$(lanes "$d")"
! seated "$out" "Application Security Engineer" && ok "sensitive words in prose alone do not seat AppSec" || bad "prose-only sensitivity" "$out"

d="$(scratch)"; touchf "$d/apps/api/app/Services/C.php" 'if ($user->password === $x) {}'; git -C "$d" add -A; git -C "$d" commit -qm c
out="$(lanes "$d")"
seated "$out" "Application Security Engineer" && ok "sensitive content in a COMMITTED file seats AppSec" || bad "committed sensitive content" "$out"

d="$(scratch)"; touchf "$d/apps/api/resources/prompts/p.md" 'ignore the password check'; git -C "$d" add -A; git -C "$d" commit -qm c
out="$(lanes "$d")"
seated "$out" "Application Security Engineer" && ok "a COMMITTED prompt .md under apps/ is content-scanned" || bad "committed prompt .md scan" "$out"

d="$(scratch)"; touchf "$d/apps/api/app/A.php" 'checkPassword($x);'; touchf "$d/:!*" 'x'; git -C "$d" add -A; git -C "$d" commit -qm c
out="$(lanes "$d")"
seated "$out" "Application Security Engineer" && ok "a committed file named like pathspec magic does not empty the scan" || bad "pathspec magic name" "$out"

d="$(scratch)"; touchf "$d/apps/api/app/Services/it's señal.php" 'if ($user->password === $x) {}'
out="$(lanes "$d")"
seated "$out" "Application Security Engineer" && seated "$out" "Backend Architect" \
  && ok "a quote and non-ASCII in an untracked name still scan and still match path rules" || bad "quoted / non-ASCII path" "$out"

# ---------------------------------------------------------------- fail closed
d="$(scratch)"; touchf "$d/apps/api/app/A.php"; git -C "$d" add -A; git -C "$d" commit -qm c; git -C "$d" branch -D main -q
out="$(lanes "$d")"; rc=$?
[ $rc -ne 0 ] && printf '%s' "$out" | grep -q '^LANES: unknown' && ok "no base ref → LANES: unknown, exit 1" || bad "no base ref" "rc=$rc $out"
e="$(cd "$d" && { bash .claude/skills/audit-agency/record-receipt.sh clean >/dev/null; } 2>&1)"; rc=$?
[ $rc -ne 0 ] && printf '%s' "$e" | grep -q '^refused:' && ok "receipt REFUSED when the selector cannot read the diff" || bad "receipt on unknown" "rc=$rc $e"

# ---------------------------------------------------------------- output shape
d="$(scratch)"; touchf "$d/apps/api/app/Models/A.php"; touchf "$d/.claude/hooks/x.sh"
out="$(lanes "$d")"
cnt="$(printf '%s\n' "$out" | grep -c -- '- Code Reviewer')"
[ "$cnt" -eq 1 ] && ok "a seat selected by two rules is listed once" || bad "dedup" "listed $cnt times"

d="$(scratch)"; touchf "$d/apps/web/src/Checkout.tsx"
out="$(lanes "$d")"
printf '%s' "$out" | grep -q '^UNMATCHED' && printf '%s' "$out" | grep -q 'apps/web/src/Checkout.tsx' \
  && ok "an unknown area is reported as UNMATCHED" || bad "unmatched area" "$out"

d="$(scratch)"; touchf "$d/apps/api/app/Models/A.php"
receipt "$d" clean >/dev/null
grep -q '"selector_changed_by_this_diff": false' "$d/.claude/state/audit-receipt.json" && ok "receipt says the selector was untouched" || bad "self-mod flag false" "$(cat "$d/.claude/state/audit-receipt.json")"
if grep -q '"Senior SecOps Engineer"' "$d/.claude/state/audit-receipt.json" && grep -q '"Backend Architect"' "$d/.claude/state/audit-receipt.json"; then
  ok "receipt records the required lanes"; else bad "receipt lanes" "$(cat "$d/.claude/state/audit-receipt.json")"; fi

d="$(scratch)"; touchf "$d/.claude/skills/audit-agency/select-lanes.sh" 'echo "LANES: none — documentation only"'
e="$(receipt "$d" clean)"
grep -q '"selector_changed_by_this_diff": true' "$d/.claude/state/audit-receipt.json" && printf '%s' "$e" | grep -q '^note:' \
  && ok "a diff that edits the selector is flagged in the receipt and on stderr" || bad "self-mod flag true" "$e"

d="$(scratch)"; touchf "$d/apps/api/app/A.php"; git -C "$d" add -A; git -C "$d" commit -qm c; touchf "$d/.claude/hooks/new-guard.sh" 'x'
grounded "$d"   # after the untracked file exists: the marker is keyed to the tree
(cd "$d" && bash .claude/skills/audit-agency/record-receipt.sh clean >/dev/null 2>&1)
grep -q '"selector_changed_by_this_diff": true' "$d/.claude/state/audit-receipt.json" \
  && ok "an UNTRACKED new hook flags the receipt" || bad "self-mod untracked hook" "$(cat "$d/.claude/state/audit-receipt.json")"

d="$(scratch)"; other="$(scratch)"; touchf "$d/apps/api/app/Models/A.php"
(cd "$d" && git add -A && git commit -qm c) >/dev/null 2>&1
grounded "$d"
(cd "$d" && CLAUDE_PROJECT_DIR="$other" bash .claude/skills/audit-agency/record-receipt.sh clean >/dev/null 2>&1)
[ -f "$d/.claude/state/audit-receipt.json" ] && [ ! -f "$other/.claude/state/audit-receipt.json" ] \
  && ok "CLAUDE_PROJECT_DIR cannot redirect a receipt into another repo" || bad "receipt redirect" "in=$([ -f "$d/.claude/state/audit-receipt.json" ] && echo yes || echo no) other=$([ -f "$other/.claude/state/audit-receipt.json" ] && echo yes || echo no)"

printf '\n%d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
