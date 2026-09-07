#!/usr/bin/env bash
# Run Reelmap's quality gates for the areas this branch actually touches.
#
# The area→gate mapping mirrors .github/workflows/ci.yml exactly, so a green run
# here means a green `api` / `contracts` / `mobile` job there.
#
#   ./run-gates.sh            # gates for areas changed vs main (+ working tree)
#   ./run-gates.sh --all      # every gate, regardless of the diff
#   ./run-gates.sh api mobile # only the named areas (api | contracts | mobile | tooling)
#
# `tooling` runs the .claude/ test suites — and runs them by executing repo
# shell, so it is the one area that should be read before it is run on a branch
# you did not write.
#
# Every selected gate runs even after an earlier one fails — one invocation
# surfaces the full list of problems instead of just the first.
set -uo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)
cd "$root" || exit 1

compose="docker compose -f $root/apps/api/compose.yaml"
sail() { $compose exec -T laravel.test "$@"; }

# shellcheck source-path=SCRIPTDIR
# shellcheck source=../../lib/use-node.sh
. "$root/.claude/lib/use-node.sh"
use_node

# ---------------------------------------------------------------- area selection
changed_paths() {
  local base ref
  if [ "$(git rev-parse --abbrev-ref HEAD)" = "main" ]; then
    base=HEAD
  else
    # A fresh clone may have no local `main`, only origin/main. Falling straight
    # through to HEAD there would silently report "nothing changed" and skip
    # every gate — the worst failure mode for a pre-PR check.
    for ref in main origin/main; do
      base=$(git merge-base HEAD "$ref" 2>/dev/null) && break
      base=""
    done
    [ -n "$base" ] || {
      echo "Could not find a merge-base against main or origin/main; gating on the working tree only." >&2
      base=HEAD
    }
  fi
  { git diff --name-only "$base"; git status --porcelain | cut -c4-; } | sort -u
}

run_api=0 run_contracts=0 run_mobile=0 run_tooling=0

if [ "${1:-}" = "--all" ]; then
  run_api=1 run_contracts=1 run_mobile=1 run_tooling=1
elif [ $# -gt 0 ]; then
  for a in "$@"; do
    case "$a" in
      api) run_api=1 ;;
      contracts) run_contracts=1 ;;
      mobile) run_mobile=1 ;;
      tooling) run_tooling=1 ;;
      *) echo "unknown area: $a (expected api | contracts | mobile | tooling)" >&2; exit 64 ;;
    esac
  done
else
  # Same filters as the `changes` job in ci.yml.
  while IFS= read -r f; do
    case "$f" in
      apps/api/*) run_api=1 ;;
      packages/contracts/*) run_contracts=1; run_mobile=1 ;;
      apps/mobile/*) run_mobile=1 ;;
      package.json|package-lock.json) run_contracts=1; run_mobile=1 ;;
      .github/workflows/ci.yml) run_api=1; run_contracts=1; run_mobile=1 ;;
      .claude/*) run_tooling=1 ;;
    esac
  done < <(changed_paths)
fi

if [ $((run_api + run_contracts + run_mobile + run_tooling)) -eq 0 ]; then
  echo "No gated paths changed vs main — nothing to run. (Use --all to force.)"
  exit 0
fi

# ---------------------------------------------------------------------- harness
declare -a passed=() failed=()

gate() { # gate <label> <command...>
  local label=$1 rc=0; shift
  printf '\n\033[1m▶ %s\033[0m\n' "$label"
  "$@" || rc=$?
  if [ $rc -eq 0 ]; then
    passed+=("$label")
  else
    # Carry the code into the label, so the SUMMARY says it too. Inline-only was
    # not enough: under --all, a dozen screens of later gate output sit between
    # the failure and the summary block a reader actually acts on, and there a
    # fired bound and a red suite were byte-identical. (A local, not
    # `${failed[-1]}` — macOS ships bash 3.2, which has no negative index.)
    local msg
    # 124 AND 137: `timeout -k` sends SIGTERM, then SIGKILL if that is ignored,
    # and only the first path exits 124 — the second reports 128+9. A pest
    # `--parallel` worker or a debugger-attached process reaches it.
    #
    # 137 is NOT only ours: a container OOM-kill exits 137 too. So the message
    # says "killed by a signal" and names both causes, rather than asserting the
    # bound fired — telling someone to raise a time bound when they are out of
    # memory is the wrong hour to hand them.
    if [ "$rc" -eq 124 ]; then
      msg="$label — TIMED OUT (exit 124): a bound fired, not a red suite — check the output above for WHICH entry (no Pest output at all means the setup entry died and no test ran)"
    elif [ "$rc" -eq 137 ]; then
      msg="$label — KILLED (exit 137): a signal, not a red suite — a time bound escalating, or an OOM kill"
    else
      msg="$label (exit $rc)"
    fi
    failed+=("$msg")
    printf '\033[31m✗ %s\033[0m\n' "$msg"
  fi
}

# ------------------------------------------------------------------------- api
if [ $run_api -eq 1 ]; then
  if ! $compose ps --status running --services 2>/dev/null | grep -qx 'laravel.test'; then
    echo "The Sail stack is not running — API gates need it (local PHP is 8.2, too old)." >&2
    echo "Start it with:  ./scripts/dev.sh backend" >&2
    failed+=("api (stack down)")
  else
    gate "API · Pint (composer lint)"   sail composer lint
    gate "API · PHPStan (composer stan)" sail composer stan
    # No `timeout` wrapper here on purpose: the bound lives in the `test` script
    # itself (apps/api/composer.json), beside the `disableProcessTimeout` that
    # removed it, so it covers CI and a bare `docker compose exec … composer test`
    # too — not just the one caller someone remembered to edit. Exit 124 from
    # this gate is that bound firing; `gate()` says so.
    gate "API · Pest (composer test)"    sail composer test
  fi
fi

# ------------------------------------------------------------------- contracts
if [ $run_contracts -eq 1 ]; then
  # CI regenerates and fails on drift rather than trusting the committed output.
  gate "Contracts · codegen + drift" bash -c '
    npm run --silent generate -w packages/contracts &&
    git diff --exit-code -- packages/contracts/src/generated || {
      echo "Generated contracts are stale — commit the regenerated files above."; exit 1;
    }'
  gate "Contracts · typecheck" npm run --silent typecheck -w packages/contracts
  gate "Contracts · Jest"      npm test --silent -w packages/contracts
fi

# ---------------------------------------------------------------------- mobile
if [ $run_mobile -eq 1 ]; then
  gate "Mobile · ESLint"    npm run --silent lint -w apps/mobile
  gate "Mobile · typecheck" npm run --silent typecheck -w apps/mobile
  gate "Mobile · Jest"      npm test --silent -w apps/mobile -- --ci
fi

# --------------------------------------------------------------------- tooling
# The hooks in .claude/ gate real work, so they get tested like anything else.
# Not part of CI (ci.yml has no tooling job yet) — this is the only thing that
# runs them, so keep it in the local gate matrix.
if [ $run_tooling -eq 1 ]; then
  # Both trees: the hooks' tests, and the skills' own (this script's `gate()`
  # reporting has one — a test nothing runs is not a test).
  #
  # This runs `bash` on every matching file, with your privileges — and the repo
  # is PUBLIC, so anyone can open a PR adding one. `tooling` auto-selects on any
  # `.claude/*` change, which means reviewing a contributor's branch by running
  # the gates would execute their file before anyone read it.
  #
  # So provenance decides, not the path: a test file identical to the one on
  # `main` has been reviewed and runs; one this branch ADDS or MODIFIES has not,
  # and is listed and skipped unless you opt in. Narrowing the glob instead would
  # be theatre — a hostile file is as easily named `.claude/hooks/tests/x.test.sh`,
  # which this matrix has always swept up.
  untrusted=""
  for t in .claude/hooks/tests/*.test.sh .claude/skills/*/tests/*.test.sh; do
    [ -e "$t" ] || continue
    if [ "${REELMAP_GATES_RUN_REPO_SHELL:-0}" = "1" ] \
       || git diff --quiet main -- "$t" 2>/dev/null; then
      gate "Tooling · $(basename "$t")" bash "$t"
    else
      untrusted="$untrusted  $t"$'\n'
    fi
  done

  if [ -n "$untrusted" ]; then
    printf '\n\033[33m⚠ Tooling: %d test file(s) differ from main and were NOT run.\033[0m\n' \
      "$(printf '%s' "$untrusted" | grep -c .)"
    printf '%s' "$untrusted"
    printf '  Read them, then re-run with REELMAP_GATES_RUN_REPO_SHELL=1 to execute them.\n'
    failed+=("Tooling · unreviewed test files skipped (see above)")
  fi
fi

# -------------------------------------------------------------------- summary
printf '\n\033[1m── Gate summary ──\033[0m\n'
# `${arr[@]:-}` + the -n test, rather than a bare `${arr[@]}`: under `set -u`,
# expanding an empty array is an error on bash 3.2 (what macOS ships). The
# fallback yields one empty element, which the -n test drops.
for g in "${passed[@]:-}"; do [ -n "$g" ] && printf '\033[32m  ✓ %s\033[0m\n' "$g"; done
for g in "${failed[@]:-}"; do [ -n "$g" ] && printf '\033[31m  ✗ %s\033[0m\n' "$g"; done

if [ ${#failed[@]} -gt 0 ]; then
  printf '\n%d gate(s) failed.\n' "${#failed[@]}"
  exit 1
fi
printf '\nAll gates green.\n'
