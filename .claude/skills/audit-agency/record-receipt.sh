#!/usr/bin/env bash
# Record that the agency audit ran against the CURRENT state of the branch.
#
# The receipt is keyed to HEAD **and** the working tree, so it stops being valid
# the moment either moves: a new commit, an amend, a rebase, or an uncommitted
# edit. That is the whole point — a receipt that survives a change would certify
# code nobody audited, which is the failure mode this exists to prevent (see
# `green-check-is-not-a-review`: a check that reads "pass" while never looking
# is worse than no check, because it stops anyone else from looking either).
set -euo pipefail

cd "${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel)}"

verdict=${1:-}
if [ -z "$verdict" ]; then
  echo "usage: record-receipt.sh <clean|findings-fixed> [note]" >&2
  echo "  Record ONLY after every 🔴 and 🟡 is fixed or explicitly waived by the owner." >&2
  exit 2
fi
case "$verdict" in
  clean | findings-fixed) ;;
  *)
    echo "unknown verdict '$verdict' (expected: clean | findings-fixed)" >&2
    exit 2
    ;;
esac

mkdir -p .claude/state
head=$(git rev-parse HEAD)
# Tracked-file state, staged and unstaged, so an uncommitted edit invalidates.
tree=$(git status --porcelain=v1 | shasum -a 256 | cut -d' ' -f1)

cat > .claude/state/audit-receipt.json <<JSON
{
  "head": "$head",
  "tree": "$tree",
  "branch": "$(git rev-parse --abbrev-ref HEAD)",
  "verdict": "$verdict",
  "note": "${2:-}",
  "recorded_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

echo "✅ Agency audit receipt recorded for ${head:0:8} ($verdict)."
