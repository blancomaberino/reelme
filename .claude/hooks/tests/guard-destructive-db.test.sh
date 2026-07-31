#!/usr/bin/env bash
# Regression tests for guard-destructive-db.sh.
#
#   bash .claude/hooks/tests/guard-destructive-db.test.sh
#
# NOTE: run this as a FILE, never by pasting the cases into a shell command —
# the guard matches on command text, so a command line containing `migrate:fresh`
# is itself refused. That is the intended (safe-direction) false positive.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../../.." || exit 1
HOOK=.claude/hooks/guard-destructive-db.sh

fail=0

verdict() { # verdict <command> -> allow|deny
  # An allowed command produces NO output (exit 0, silent), so absence of output
  # is the allow signal — jq's `// "allow"` cannot fire on empty input.
  local out
  out=$(printf '{"tool_name":"Bash","tool_input":{"command":%s}}' \
          "$(printf '%s' "$1" | jq -Rs .)" | bash "$HOOK")
  if [ -z "$out" ]; then
    echo allow
  else
    printf '%s' "$out" | jq -r '.hookSpecificOutput.permissionDecision // "allow"'
  fi
}

check() { # check <expected> <command> <label>
  local got; got=$(verdict "$2")
  if [ "$got" = "$1" ]; then
    echo "  ok   $3 -> $got"
  else
    echo "  FAIL $3 -> $got (want $1)"; fail=1
  fi
}

# Neither flag points at the test database — verified against this project:
# there is no apps/api/.env.testing (so --env=testing resolves to the DEV
# database `reelmap`), and `testing` is not among the configured connections
# (sqlite/mysql/mariadb/pgsql/sqlsrv). Both must therefore be refused.
echo "--- fake 'testing' escape hatches must be refused ---"
check deny "php artisan migrate:fresh --env=testing"     "migrate:fresh --env=testing"
check deny "php artisan db:wipe --database=testing"      "db:wipe --database=testing"

echo "--- destructive verbs, however they are invoked ---"
check deny "php artisan migrate:fresh --seed"            "migrate:fresh --seed"
check deny "php artisan db:wipe"                         "db:wipe"
check deny "php artisan migrate:refresh"                 "migrate:refresh"
check deny "php artisan migrate:reset"                   "migrate:reset"
check deny "docker compose exec -T laravel.test php artisan migrate:fresh" "via docker exec"

echo "--- ordinary work must pass through ---"
check allow "php artisan migrate"                        "plain migrate"
check allow "php artisan migrate --force"                "migrate --force"
check allow "git status"                                 "git status"
check allow "composer test"                              "composer test"

# Matching is anchored on an artisan invocation, not on the words appearing
# anywhere. An earlier revision matched free text and refused its own commit
# message — writing about these commands has to stay possible.
echo "--- merely NAMING the verbs is not an invocation ---"
check allow 'git commit -m "guard now denies migrate:fresh and db:wipe"' "commit message naming the verbs"
check allow "grep -rn 'migrate:fresh' ."                 "grep for the verb"
check allow "echo 'never run db:wipe on dev'"            "echo mentioning the verb"

echo "--- the single supported override ---"
got=$(REELMAP_ALLOW_DB_WIPE=1 verdict "php artisan migrate:fresh")
if [ "$got" = allow ]; then echo "  ok   REELMAP_ALLOW_DB_WIPE=1 -> allow"
else echo "  FAIL override -> $got"; fail=1; fi

# A guard that silently disables itself when a dependency is missing is worse
# than no guard, because it still looks installed.
echo "--- fails closed when jq is unavailable ---"
got=$(printf '{"tool_name":"Bash","tool_input":{"command":"php artisan migrate:fresh"}}' \
      | env PATH=/usr/bin:/bin bash "$HOOK" 2>/dev/null | grep -c permissionDecision || true)
if [ "$got" != 0 ]; then echo "  ok   no jq -> still refuses"
else echo "  FAIL no jq -> allowed through"; fail=1; fi

echo
if [ "$fail" = 0 ]; then echo "ALL CASES PASS"; else echo "SOME CASES FAILED"; fi
exit "$fail"
