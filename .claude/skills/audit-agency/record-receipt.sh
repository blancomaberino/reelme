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

# The repo the caller is IN, not CLAUDE_PROJECT_DIR: the env var names the
# session's project, and a receipt written there from another checkout (a
# test's scratch repo, a worktree) would certify code nobody audited.
top="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "refused: not inside a git repository — no receipt written" >&2; exit 2; }
cd "$top"

verdict=${1:-}
[ $# -gt 0 ] && shift

# A shift LOOP, not a scan over "$@". The scan read `$2` for the note while also
# matching `--declines` anywhere, so `record-receipt.sh findings-fixed --declines
# none` made the flag its own note: `$2` was non-empty, the leads refusal below
# was satisfied by the flag's name, and the receipt recorded `"note":
# "--declines"` over a lead nobody had written a sentence about. A new gate that
# disables the gate beside it. Both review seats reproduced it.
note=""
declines=""
declines_given=""
while [ $# -gt 0 ]; do
  case "$1" in
    --declines)
      [ -n "$declines_given" ] && { echo "refused: --declines given twice" >&2; exit 2; }
      # A value that is absent or looks like another flag is a typo, not an
      # answer — and taking it silently is how `--declines --force` became the
      # recorded disposition.
      case "${2:-}" in '' | --*) echo "refused: --declines needs a value (none, or what was declined)" >&2; exit 2 ;; esac
      declines_given=1
      declines=$2
      shift 2
      ;;
    --*) echo "refused: unknown option '$1'" >&2; exit 2 ;;
    *)
      [ -z "$note" ] || { echo "refused: unexpected argument '$1'" >&2; exit 2; }
      note=$1
      shift
      ;;
  esac
done

if [ -z "$verdict" ]; then
  echo "usage: record-receipt.sh <clean|findings-fixed> [note] --declines <none|what you declined>" >&2
  echo "       record-receipt.sh docs-only [note]" >&2
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
# `|| true`: the selector exits 1 on "unknown", and under set -e + pipefail that
# killed this script before the refusal below could say why.
lanes="$({ .claude/skills/audit-agency/select-lanes.sh 2>/dev/null || true; } | sed -n '/^LANES/,/^$/{/^$/d;p;}')"
# Fail CLOSED on an empty or unknown selection: a selector that crashed, or a
# base ref that did not resolve, must not become a receipt of any verdict.
if [ -z "$lanes" ] || printf '%s' "$lanes" | grep -q '^LANES: unknown'; then
  echo "refused: select-lanes.sh could not read the diff — no receipt written:" >&2
  printf '%s\n' "${lanes:-(no output)}" >&2
  exit 2
fi
# Say so when the diff changes the very files that produce this receipt.
#
# `^\.claude/` — the whole directory, not a list. The list version named
# audit-agency, hooks and settings.json, and missed `skills/gates/` (the runner
# and its checks), `lib/` (which run-gates SOURCES before any area is selected)
# and `agents/` (the seats themselves). Three misses in one enumeration is the
# §3 rule arriving: replace the cases with the rule that covers them. Untracked
# files count — the tree hash does.
self_mod=""
# No fallback to HEAD: a diff against HEAD is empty, and the flag would read
# false on exactly the branch nobody can audit. Fail closed like the selector.
_mb="$(git merge-base origin/main HEAD 2>/dev/null || git merge-base main HEAD 2>/dev/null)" \
  || { echo "refused: no base ref (origin/main, main) — no receipt written" >&2; exit 2; }
if { git diff --name-only "$_mb" 2>/dev/null; git ls-files --others --exclude-standard 2>/dev/null; } \
     | grep -qE '^\.claude/'; then
  self_mod=1
  echo "note: this diff changes the audit skill, a hook, or .claude/settings.json — the receipt is produced by code the diff itself changed; the Code Reviewer seat is mandatory here." >&2
fi
if [ "$verdict" = docs-only ] && ! printf '%s' "$lanes" | grep -q '^LANES: none — documentation only'; then
  echo "refused: 'docs-only' but the diff selects seats:" >&2
  printf '%s\n' "$lanes" >&2
  exit 2
fi

# The grounding pass must have run against THIS tree. CLAUDE.md §2 makes the
# review step `/coderabbit`, which seats these lanes as one axis alongside a
# grounding pass that is a script — gitleaks, semgrep, osv-scanner, actionlint,
# hadolint, shellcheck, and the wrong-reason-assertion heuristics. Seating the
# lanes without it is half a review, and it is the half that cannot be argued
# out of a finding. A whole session shipped that way before this check existed.
grounding_state="$(PYTHONDONTWRITEBYTECODE=1 python3 .claude/skills/audit-agency/check-grounding.py 2>/dev/null || echo missing)"
case "$grounding_state" in
  ok) ;;
  skipped)
    echo "note: the grounding pass was SKIPPED for this tree — the receipt records it, and the PR body must justify it." >&2
    ;;
  *)
    cat >&2 <<MSG
refused: no grounding pass for this tree ($grounding_state) — no receipt written.

The lanes are one axis of the review, not the review. Run:

    .claude/skills/audit-agency/run-grounding.sh

then record the receipt again. If the tree changed after the grounding pass, it
ran against code nobody is shipping — the same reason this receipt is keyed to
HEAD + tree.
MSG
    exit 2
    ;;
esac

# The grounding pass records how many leads it raised, and until now nothing
# read that number — so a `clean` receipt over a log with thirty ⚠️ was
# well-formed. A lead is not a finding, but it is a thing somebody has to have
# looked at, and the note is where that shows.
if [ "$verdict" != docs-only ] && [ -z "$note" ]; then
  # int() inside the Python, not in the shell test: a hand-written marker with
  # "leads": "many" made `[ "$leads" -gt 0 ]` error and evaluate FALSE, which
  # skipped the requirement instead of enforcing it.
  # A missing, non-numeric or negative `leads` used to become 0 and skip the
  # requirement — failing OPEN on a malformed marker, in a file whose whole
  # ethos is the opposite. `-1` is the sentinel for "cannot tell", and the
  # branch below treats it like a positive count.
  leads="$(python3 -c 'import json
try:
    v = json.load(open(".claude/state/grounding.json"))["leads"]
    print(v if isinstance(v, int) and not isinstance(v, bool) and v >= 0 else -1)
except Exception:
    print(-1)' 2>/dev/null || echo -1)"
  case "$leads" in ''|*[!0-9-]*) leads=-1 ;; esac
  if [ "$leads" -ne 0 ]; then
    if [ "$leads" -lt 0 ]; then
      echo "refused: the grounding marker's lead count is missing or not a whole number, so this" >&2
      echo "receipt cannot claim the leads were read. Re-run run-grounding.sh." >&2
      exit 2
    fi
    echo "refused: the grounding pass raised $leads lead(s) and this receipt carries no note." >&2
    echo "Say what you checked them against — a count nobody wrote a sentence about is a count nobody read:" >&2
    echo "  record-receipt.sh $verdict \"<what the leads were, and what you did>\"" >&2
    exit 2
  fi
fi

# Every finding is disposed of before the receipt — fixed, declined, or bounded
# (CLAUDE.md §4) — and a 🔴 or 🟡 needs an owner waiver to be declined or bounded.
# Nothing could check that: the receipt hashes HEAD and the tree and has never
# known what a finding is, so a declined blocker left no trace at all.
#
# This does not fix that, and saying it would be the same false comfort the rule
# is about. What it does is force the QUESTION to be answered: `--declines none`
# is a claim on the record, and omitting it is a refusal rather than a silence.
#
# The precedent is `approve.sh --simplify`, which is a bare attestation and says
# so. An earlier version of this comment cited `--panel` instead — which is the
# one flag in that file explicitly HARDENED out of being an attestation: it
# cross-checks every name against `record-panel.sh`'s log for the exact HEAD sha,
# because "a gate that takes the author's word is the 'check that cannot fail'
# this skill exists to find". Citing it here had the argument backwards. The
# evidence-backed version of THIS field is a findings log written as each seat
# returns, with `--declines` checked against the unresolved entries; that is the
# upgrade path, and this is not it.
#
# Empty is refused as well as absent: "" is the shape you reach for when you want
# the field gone, and a receipt whose `declines` is blank reads as "asked and not
# answered", which is the state this exists to remove.
#
# Exempt ONLY the verdict this script can prove. `docs-only` is checked against
# select-lanes.sh above, so it is the one verdict that is not a self-assertion —
# and a diff that seats nobody has no findings to dispose of.
#
# `clean` is NOT exempt, and an earlier version of this made it so. The reasoning
# was that `clean` already asserts no findings were raised, so the field would be
# a question with one possible answer. Review took that apart: nothing here or in
# `guard-pr-audit.py` ties the verdict to any finding, so `clean` is a free
# self-assertion by the same agent deciding whether to decline — which made
# "type `clean`" the cheapest way past this refusal. An escape that costs one
# word is the inverted gradient CLAUDE.md §4 was rewritten to remove, reappearing
# in the mechanism meant to enforce it.
#
# Requiring it on `clean` too cost fifteen call sites in the suites. That is a
# migration cost, not an argument.
if [ "$verdict" != docs-only ] && { [ -z "$declines_given" ] || [ -z "$declines" ]; }; then
  echo "refused: this receipt carries no --declines." >&2
  echo "Every finding is fixed, declined, or bounded before the receipt (CLAUDE.md §4)," >&2
  echo "and a 🔴 or 🟡 needs an owner waiver to be declined. Say which:" >&2
  echo "  record-receipt.sh $verdict \"<note>\" --declines none" >&2
  echo "  record-receipt.sh $verdict \"<note>\" --declines \"T-###: <what, and the waiver>\"" >&2
  exit 2
fi

mkdir -p .claude/state

VERDICT="$verdict" NOTE="$note" DECLINES="$declines" LANES="$lanes" SELF_MOD="$self_mod" GROUNDING="$grounding_state" PYTHONDONTWRITEBYTECODE=1 python3 - <<'PY'
import importlib.util, json, os, pathlib, subprocess
from datetime import datetime, timezone

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
            "declines": os.environ["DECLINES"],
            "selector_changed_by_this_diff": bool(os.environ["SELF_MOD"]),
            "grounding": os.environ["GROUNDING"],
            "required_lanes": [
                l.strip()[2:].split("—")[0].strip()
                for l in os.environ["LANES"].splitlines()
                if l.strip().startswith("- ")
            ],
            "recorded_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        },
        indent=2,
    )
    + "\n"
)
print(f"✅ Agency audit receipt recorded for {head[:8]} ({os.environ['VERDICT']}).")
PY
