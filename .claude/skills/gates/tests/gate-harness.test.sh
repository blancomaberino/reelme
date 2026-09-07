#!/usr/bin/env bash
# Tests run-gates.sh's `gate()` reporting.
#
# It exists because the distinction it draws is invisible when it is wrong: a
# suite killed by its time bound and a suite with two red tests both printed a
# bare `✗ API · Pest (composer test)`, in the summary block and nowhere else,
# with truncated output either way. An audit caught that; this keeps it caught.
#
# gate() is extracted from the script rather than copied — a copy would pass
# forever after the original changed.
set -uo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
script="$here/../run-gates.sh"
[ -f "$script" ] || { echo "FAIL: cannot find run-gates.sh at $script"; exit 1; }

# shellcheck disable=SC2016
body=$(sed -n '/^gate() {/,/^}/p' "$script")
[ -n "$body" ] || { echo "FAIL: could not extract gate() from run-gates.sh"; exit 1; }

declare -a passed=() failed=()
eval "$body"

fails=0
check() { # check <name> <expected-substring> <actual>
  if printf '%s' "$3" | grep -qF -- "$2"; then
    printf 'PASS  [%s]\n' "$1"
  else
    printf 'FAIL  [%s]\n  want substring: %s\n  got: %s\n' "$1" "$2" "$3"
    fails=$((fails + 1))
  fi
}

# NOT `$(gate …)`: command substitution runs in a subshell, so the arrays the
# summary is built from would not survive the call — and the summary is the half
# under test. Capture through a file instead.
tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT

gate "timed-out" bash -c 'exit 124' >"$tmp" 2>&1
out=$(cat "$tmp")
check "inline: 124 names the bound" "TIMED OUT (exit 124)" "$out"
check "summary: 124 keeps the code" "TIMED OUT (exit 124)" "${failed[0]}"

# 137, not just 124: `timeout -k` escalates to SIGKILL when SIGTERM is ignored,
# and that path reports 128+9. Both are the bound firing and must read alike.
gate "killed-hard" bash -c 'exit 137' >"$tmp" 2>&1
out=$(cat "$tmp")
check "inline: 137 also names the bound" "TIMED OUT (exit 137)" "$out"
check "summary: 137 keeps the code" "TIMED OUT (exit 137)" "${failed[1]}"

gate "red-suite" bash -c 'exit 3' >"$tmp" 2>&1
out=$(cat "$tmp")
check "inline: a real failure shows its code" "(exit 3)" "$out"
check "summary: a real failure shows its code" "red-suite (exit 3)" "${failed[2]}"

# Render the ACTUAL summary block, don't just read the array. Asserting on
# `failed[]` would stay green if the summary loop were changed to print, say,
# "${g%% —*}" — which strips the annotation and restores the exact regression
# this file exists to catch. So extract that loop from the script too and run it.
summary_body=$(sed -n '/^for g in "${failed\[@\]:-}"/p' "$script")
[ -n "$summary_body" ] || {
  echo "FAIL: could not EXTRACT the failed-summary loop from run-gates.sh."
  echo "      This is not a reporting regression — the loop was renamed,"
  echo "      reformatted, or split across lines. Fix the sed pattern here."
  exit 1
}
summary=$(eval "$summary_body" 2>&1)

check "summary render: names the fired bound" "TIMED OUT (exit 124)" "$summary"
check "summary render: names a real failure's code" "red-suite (exit 3)" "$summary"

# The whole point: the two must not read alike where the reader acts.
if [ "${failed[0]}" = "${failed[2]}" ]; then
  echo "FAIL  [a fired bound and a red suite are indistinguishable in the summary]"
  fails=$((fails + 1))
else
  echo "PASS  [a fired bound and a red suite differ in the summary]"
fi

gate "green" true >/dev/null 2>&1
check "success is recorded plainly" "green" "${passed[0]}"
if [ ${#failed[@]} -ne 3 ]; then
  echo "FAIL  [success must not add to failed: ${#failed[@]} != 3]"
  fails=$((fails + 1))
else
  echo "PASS  [success does not touch the failed list]"
fi

if [ $fails -gt 0 ]; then
  printf '\n%d check(s) failed.\n' "$fails"
  exit 1
fi
echo
echo "ALL PASS"
