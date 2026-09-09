#!/usr/bin/env bash
# Tests vacuous-assertions.sh — the gate that fails on assertions that cannot
# fail. A lint nobody tests is a lint that silently stops matching.
#
# Every case runs the REAL script against a throwaway git repo, because the
# script's scope comes from `git ls-files` and its patterns are BREs whose
# backreferences behave differently under BSD and GNU grep. A copy of the
# patterns here would pass forever after the original changed.
set -uo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
script="$here/../checks/vacuous-assertions.sh"
[ -f "$script" ] || { echo "FAIL: cannot find vacuous-assertions.sh at $script"; exit 1; }

fails=0
tmproot=$(mktemp -d)
trap 'rm -rf "$tmproot"' EXIT

# run_on <php-test-body> -> prints "<exit>|<output>"
run_on() {
  local dir out code path
  dir=$(mktemp -d "$tmproot/repo.XXXXXX")
  # The path is a parameter now: every case wrote apps/api/tests/Example.php, so
  # narrowing the scan to that ONE pathspec — deleting the mobile and contracts
  # scope entirely — left the suite green.
  path="${2:-apps/api/tests/Example.php}"
  mkdir -p "$dir/$(dirname "$path")"
  printf '%s\n' "$1" > "$dir/$path"
  (
    cd "$dir" || exit 1
    git init -q .
    git add -A >/dev/null 2>&1
    git -c user.email=t@t -c user.name=t commit -qm t >/dev/null 2>&1
  )
  out=$(cd "$dir" && bash "$script" 2>&1)
  code=$?
  printf '%s|%s' "$code" "$out"
}

check() { # check <name> <want-exit> <want-substring> <result>
  local want_code="$2" want="$3" got="$4"
  local code="${got%%|*}" out="${got#*|}"

  if [ "$code" = "$want_code" ] && printf '%s' "$out" | grep -qF -- "$want"; then
    printf 'PASS  [%s]\n' "$1"
  else
    printf 'FAIL  [%s]\n  want exit %s + substring: %s\n  got exit %s: %s\n' \
      "$1" "$want_code" "$want" "$code" "$out"
    fails=$((fails + 1))
  fi
}

check "assertTrue(true) is caught" 1 "constant asserted to be itself" \
  "$(run_on '<?php it("x", function () { $this->assertTrue(true); });')"

check "assertFalse(false) is caught" 1 "constant asserted to be itself" \
  "$(run_on '<?php it("x", function () { $this->assertFalse(false); });')"

check "expect(336)->toBe(336) is caught" 1 "on a literal" \
  "$(run_on '<?php it("x", function () { expect(336)->toBe(336); });')"

check "expect(\$x)->toBe(\$x) is caught" 1 'expect($x)->toBe($x)' \
  "$(run_on '<?php it("x", function () { expect($hours)->toBe($hours); });')"

check "assertSame(\$a, \$a) is caught" 1 'assertSame($x, $x)' \
  "$(run_on '<?php it("x", function () { $this->assertSame($a, $a); });')"

# The excluded rows: real assertions must NOT trip it. A lint that fires on
# working tests gets disabled, which is worse than not having it.
check "a real literal comparison passes" 0 "no vacuous assertions" \
  "$(run_on '<?php it("x", function () { expect(336)->toBe(24 * 14); });')"

check "different variables pass" 0 "no vacuous assertions" \
  "$(run_on '<?php it("x", function () { expect($hours)->toBe($expected); });')"

check "assertTrue on an expression passes" 0 "no vacuous assertions" \
  "$(run_on '<?php it("x", function () { $this->assertTrue($user->isActive()); });')"

# The whole point of a scan is that it scanned something.
empty=$(mktemp -d "$tmproot/empty.XXXXXX")
(cd "$empty" && git init -q .)
out=$(cd "$empty" && bash "$script" 2>&1); code=$?
check "an empty scan fails instead of reporting a pass" 1 "refusing to report a pass" "$code|$out"

# The other two scopes, which no case reached — and the Jest matcher, which the
# PHP-only fixtures could never exercise.
check "a mobile .test.tsx is scanned" 1 "on a literal" \
  "$(run_on 'it("x", () => { expect(3).toBe(3); });' 'apps/mobile/src/x.test.tsx')"

check "a contracts .test.ts is scanned" 1 "on a literal" \
  "$(run_on 'it("x", () => { expect(true).toBe(true); });' 'packages/contracts/src/x.test.ts')"

# A path git C-QUOTES: the quoted string used to reach grep as a filename that
# does not exist, so the file was skipped while still counted — the scan could
# report a pass having read nothing.
check "a non-ASCII filename is scanned, not silently skipped" 1 "constant asserted to be itself" \
  "$(run_on '<?php $this->assertTrue(true);' 'apps/api/tests/Café.php')"

# The known blind spot, asserted so nobody later mistakes it for coverage: the
# tautology this gate was written after is NOT detectable by it.
check "DOCUMENTED BLIND SPOT: a config-pinned tautology is not caught" 0 "no vacuous assertions" \
  "$(run_on '<?php beforeEach(function () { config()->set("logging.channels.daily.days", 14); });
it("x", function () { $hours = 24 * (int) config("logging.channels.daily.days"); expect($hours)->toBe(336); });')"

[ $fails -eq 0 ] && echo "ALL PASS" || echo "$fails FAILED"
exit $((fails > 0))
