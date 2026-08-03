#!/usr/bin/env bash
# Cases for the simctl-deeplink guard.
# Run: bash .claude/hooks/tests/guard-simulator-deeplink.test.sh
#
# The commands under test are assembled from fragments rather than written out
# literally, because the guard is INSTALLED while these run — a literal
# invocation in this file would be blocked by the very hook it exercises.
set -uo pipefail
GUARD="$(cd "$(dirname "$0")/.." && pwd)/guard-simulator-deeplink.sh"
pass=0
fail=0

OPEN="open""url" # split so this file never contains the bare token

denies() {
  printf '{"tool_input":{"command":%s}}' "$(printf '%s' "$1" | jq -Rs .)" |
    "$GUARD" 2>/dev/null | grep -q '"permissionDecision": *"deny"'
}

check() { # <deny|allow> <command> <description>
  local want=$1 cmd=$2 desc=$3 got
  if denies "$cmd"; then got=deny; else got=allow; fi
  if [ "$got" = "$want" ]; then
    pass=$((pass + 1))
  else
    fail=$((fail + 1))
    echo "  ✗ expected $want, got $got: $desc"
  fi
}

# --- must BLOCK: the real invocations that caused three "stuck page" reports ---
check deny "xcrun simctl $OPEN booted reelmap://offers" 'plain invocation'
check deny "simctl $OPEN booted reelmap://map" 'without the xcrun prefix'
check deny "xcrun simctl $OPEN B0C916DF-556B reelmap://x" 'explicit udid'
# The form that actually slipped through: a setup step chained onto something else.
check deny "sleep 2; xcrun simctl $OPEN booted reelmap://offers >/dev/null" 'chained as a setup step'

# --- must ALLOW: the sanctioned tools ---
check allow '~/.maestro/bin/maestro test /tmp/flow.yaml' 'maestro navigation'
check allow 'xcrun simctl io booted screenshot /tmp/a.png' 'screenshot'
check allow 'xcrun simctl launch booted pet.one.reelmap' 'plain launch (this is what CLEARS the url)'
check allow 'xcrun simctl terminate booted pet.one.reelmap' 'terminate'
check allow 'xcrun simctl location booted set -34.9011,-56.1645' 'location reset'

# --- must ALLOW: text that merely NAMES the command ---
# Regressions the DB guard hit (refusing its own commit message) and this one hit
# (refusing the file that tests it).
check allow "git commit -m \"docs: ban simctl $OPEN for navigation\"" 'commit message naming it'
check allow "grep -rn 'simctl $OPEN' CLAUDE.md" 'grepping for it'

echo "pass=$pass fail=$fail"
[ "$fail" -eq 0 ]
