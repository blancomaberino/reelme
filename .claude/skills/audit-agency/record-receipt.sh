#!/usr/bin/env bash
# Record that the agency audit ran against the CURRENT state of the branch.
#
# The receipt is keyed to HEAD **and** the working tree's CONTENT, so it stops
# being valid the moment either moves: a new commit, an amend, a rebase, or an
# uncommitted edit. That is the whole point — a receipt that survived a change
# would certify code nobody audited, which is the failure this exists to
# prevent (see `green-check-is-not-a-review`: a check that reads "pass" while
# never looking is worse than no check, because it stops anyone else looking).
#
# The hash is computed by importing the hook that CHECKS it, never by
# reimplementing it here. Two copies of this calculation would drift, and the
# failure mode of drift is a receipt that can never match — or, far worse, one
# that matches when it should not.
set -euo pipefail

cd "${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel)}"

verdict=${1:-}
if [ -z "$verdict" ]; then
  echo "usage: record-receipt.sh <clean|findings-fixed|docs-only> [note]" >&2
  echo "  clean / findings-fixed: ONLY after every 🔴 and 🟡 is fixed or explicitly waived by the owner." >&2
  echo "  docs-only: ONLY when select-lanes.sh reports 'LANES: none' — this script checks." >&2
  exit 2
fi
case "$verdict" in
  clean | findings-fixed | docs-only) ;;
  *)
    echo "unknown verdict '$verdict' (expected: clean | findings-fixed | docs-only)" >&2
    exit 2
    ;;
esac

# The lanes the diff SELECTS are recorded beside the verdict — so a receipt
# says what was required, and a `docs-only` receipt on a code diff is refused
# rather than written. The hook still checks only HEAD + tree; this is the one
# place the lane decision leaves an artifact.
lanes="$(.claude/skills/audit-agency/select-lanes.sh 2>/dev/null | sed -n '/^LANES/,/^$/p' | sed '/^$/d')"
if [ "$verdict" = docs-only ] && ! printf '%s' "$lanes" | grep -q '^LANES: none'; then
  echo "refused: 'docs-only' but the diff selects seats:" >&2
  printf '%s
' "$lanes" >&2
  exit 2
fi

mkdir -p .claude/state

VERDICT="$verdict" NOTE="${2:-}" LANES="$lanes" python3 - <<'PY'
import importlib.util, json, os, pathlib, subprocess

spec = importlib.util.spec_from_file_location("guard", ".claude/hooks/guard-pr-audit.py")
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

repo = os.getcwd()
head, tree = guard.state(repo)
if not head:
    raise SystemExit("not a git repository, or git failed — no receipt written")

branch = subprocess.run(
    ["git", "rev-parse", "--abbrev-ref", "HEAD"], capture_output=True, text=True
).stdout.strip()

# json.dump, not string interpolation: a note containing a quote used to corrupt
# the file, which then failed closed on the next read — harmless but sloppy.
pathlib.Path(".claude/state/audit-receipt.json").write_text(
    json.dumps(
        {
            "head": head,
            "tree": tree,
            "branch": branch,
            "verdict": os.environ["VERDICT"],
            "note": os.environ["NOTE"],
            "required_lanes": [
                l.strip()[2:].split("—")[0].strip()
                for l in os.environ["LANES"].splitlines()
                if l.strip().startswith("- ")
            ],
            "recorded_at": subprocess.run(
                ["date", "-u", "+%Y-%m-%dT%H:%M:%SZ"], capture_output=True, text=True
            ).stdout.strip(),
        },
        indent=2,
    )
    + "\n"
)
print(f"✅ Agency audit receipt recorded for {head[:8]} ({os.environ['VERDICT']}).")
PY
