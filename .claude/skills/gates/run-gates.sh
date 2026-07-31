#!/usr/bin/env bash
# Run Reelmap's quality gates for the areas this branch actually touches.
#
# The area→gate mapping mirrors .github/workflows/ci.yml exactly, so a green run
# here means a green `api` / `contracts` / `mobile` job there.
#
#   ./run-gates.sh            # gates for areas changed vs main (+ working tree)
#   ./run-gates.sh --all      # every gate, regardless of the diff
#   ./run-gates.sh api mobile # only the named areas (api | contracts | mobile)
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
  local label=$1; shift
  printf '\n\033[1m▶ %s\033[0m\n' "$label"
  if "$@"; then
    passed+=("$label")
  else
    failed+=("$label")
    printf '\033[31m✗ %s FAILED\033[0m\n' "$label"
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
  for t in .claude/hooks/tests/*.test.sh; do
    [ -e "$t" ] || continue
    gate "Tooling · $(basename "$t")" bash "$t"
  done
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
