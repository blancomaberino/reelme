#!/usr/bin/env bash
#
# vacuous-assertions.sh — fail on assertions that cannot fail.
#
# CLAUDE.md §5 bans `assertTrue(true)` and friends. Nothing enforced it, and the
# session that added this gate shipped two guards that were green for the wrong
# reason (T-156 — see docs/process/lessons.md). This catches the mechanically
# detectable half.
#
# WHAT IT CANNOT CATCH, stated up front so nobody trusts it further than it goes:
# an assertion whose expected literal happens to equal a value the test itself
# pinned earlier — `config()->set('...days', 14)` in a beforeEach, then
# `expect(24 * $days)->toBe(336)`. Textually that compares two different things.
# The rule that covers it is a design rule, not a lint: put the derivation in
# production code (App\Support\RetentionWindow) so a test can drive it with the
# values that break it.
#
# BRE, not -E/-P: backreferences are what make self-comparison detectable, and
# they are portable only in basic regexes. macOS ships bash 3.2 and BSD grep, so
# no `mapfile`, no `grep -P`, no `${arr[@]}` on an empty array.
set -uo pipefail

cd "$(git rev-parse --show-toplevel)" || exit 1

fail=0

test_files() {
  # --others: a test file written but not yet `git add`ed is exactly the one
  # being reviewed, and listing only tracked files hid it from this gate.
  git ls-files --cached --others --exclude-standard 'apps/*/tests/*.php' 'apps/*/tests/**/*.php' \
    'apps/*/**/*.test.ts' 'apps/*/**/*.test.tsx' 'packages/*/**/*.test.ts'
}

# scan <label> <bre-pattern>
scan() {
  local label="$1" pattern="$2" hits
  hits=$(test_files | tr '\n' '\0' | xargs -0 grep -n "$pattern" 2>/dev/null)

  if [ -n "$hits" ]; then
    printf '\033[31m  x %s\033[0m\n' "$label"
    printf '%s\n' "$hits" | sed 's/^/      /'
    fail=1
  fi
}

count=$(test_files | wc -l | tr -d ' ')
if [ "$count" -eq 0 ]; then
  echo "  no test files matched — refusing to report a pass on an empty scan." >&2
  exit 1
fi

# A constant asserted to be itself: the named case in §5, plus its spellings.
scan "constant asserted to be itself" \
  'assert\(True( *true *)\|False( *false *)\|Null( *null *)\)'

# expect(<literal>)->toBe(<the same literal>), Pest and Jest.
scan "expect(x)->toBe(x) on a literal" \
  'expect( *\([0-9][0-9]*\|true\|false\|null\) *) *-> *toBe( *\1 *)'
scan "expect(x).toBe(x) on a literal (Jest)" \
  'expect( *\([0-9][0-9]*\|true\|false\|null\) *) *\. *toBe( *\1 *)'

# The same VARIABLE on both sides — passes whatever it holds.
scan "expect(\$x)->toBe(\$x)" \
  'expect( *\(\$[A-Za-z_][A-Za-z0-9_]*\) *) *-> *toBe( *\1 *)'
scan "assertSame(\$x, \$x)" \
  'assert\(Same\|Equals\)( *\(\$[A-Za-z_][A-Za-z0-9_]*\) *, *\2 *)'

if [ $fail -eq 0 ]; then
  echo "  no vacuous assertions in $count test file(s)."
  exit 0
fi

cat <<'MSG'

  An assertion that cannot fail is worse than none: it buys confidence.
  Assert the OBSERVABLE the code must produce, then prove it bites by mutating
  the code and watching this test go red (CLAUDE.md §5).
MSG
exit 1
