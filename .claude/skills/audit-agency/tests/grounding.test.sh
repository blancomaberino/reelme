#!/usr/bin/env bash
# Tests the grounding requirement on the audit receipt.
#
# The rule under test: the lanes are ONE AXIS of the review (/coderabbit Phase
# 3.5), not the review. A receipt written without the grounding pass certifies a
# review that never ran gitleaks, semgrep, shellcheck or the wrong-reason-
# assertion heuristics.
#
# The first version of this gate shipped with three reproduced ways to report a
# pass while not looking. Every case below marked FALSE-GREEN is one of them,
# and each is RED against that version:
#   1. a crashing pass fills the log with its own error, so "non-empty" passed;
#   2. an absent `skipped` key is falsy, so a hand-written marker read as `ok`;
#   3. the push guard never read the field at all.
#
# Every case runs the REAL scripts in a throwaway repo, because the marker is
# keyed to a tree hash and a digest the hook computes, and a reimplementation
# here would pass forever after the originals changed.
set -uo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo_root=$(cd "$here/../../../.." && pwd)
[ -f "$repo_root/.claude/skills/audit-agency/record-receipt.sh" ] || {
  echo "FAIL: cannot locate the repo from $here"; exit 1; }

fails=0
tmproot=$(mktemp -d)
trap 'rm -rf "$tmproot"' EXIT

check() { # check <name> <want-substring> <actual>
  if printf '%s' "$3" | grep -qF -- "$2"; then
    printf 'PASS  [%s]\n' "$1"
  else
    printf 'FAIL  [%s]\n  want substring: %s\n  got: %s\n' "$1" "$2" "$3"
    fails=$((fails + 1))
  fi
}

ok() { printf 'PASS  [%s]\n' "$1"; }
bad() { printf 'FAIL  [%s]\n  %s\n' "$1" "${2:-}"; fails=$((fails + 1)); }

# A scratch repo carrying the real scripts and a code (non-docs) diff.
make_repo() {
  local dir; dir=$(mktemp -d "$tmproot/repo.XXXXXX")
  mkdir -p "$dir/.claude/skills/audit-agency/tests" "$dir/.claude/hooks" "$dir/apps/api/app"
  cp "$repo_root/.claude/skills/audit-agency/record-receipt.sh" \
     "$repo_root/.claude/skills/audit-agency/select-lanes.sh" \
     "$repo_root/.claude/skills/audit-agency/check-grounding.py" \
     "$repo_root/.claude/skills/audit-agency/run-grounding.sh" "$dir/.claude/skills/audit-agency/"
  cp "$repo_root/.claude/hooks/guard-pr-audit.py" "$dir/.claude/hooks/"
  # Mirrors the real repo: the receipt and the marker are working state, and the
  # tree hash counts untracked files — so this line is load-bearing.
  printf '.claude/state/\n.claude/hooks/__pycache__/\n' > "$dir/.gitignore"
  (
    cd "$dir" || exit 1
    git init -q -b main .
    echo "<?php" > apps/api/app/Seed.php
    git add -A >/dev/null 2>&1
    git -c user.email=t@t -c user.name=t commit -qm base >/dev/null 2>&1
    git checkout -q -b feat/x
    echo "<?php // change" > apps/api/app/Thing.php
    git add -A >/dev/null 2>&1
    git -c user.email=t@t -c user.name=t commit -qm change >/dev/null 2>&1
  )
  printf '%s' "$dir"
}

# A fake $HOME whose ground.sh behaves however a case needs.
fake_home() { # fake_home <body>
  local h; h=$(mktemp -d "$tmproot/home.XXXXXX")
  mkdir -p "$h/.claude/skills/coderabbit/scripts"
  printf '%s\n' "$1" > "$h/.claude/skills/coderabbit/scripts/ground.sh"
  printf '%s' "$h"
}

# The output shape a real pass produces, reduced to what the parser reads.
# BOTH unconditional sections: a required tool with no section at all is now a
# refusal, because absence of a skip line used to read as "it ran" — and a stub
# with one header was literally a pass that runs nothing.
REAL_PASS='#!/usr/bin/env bash
echo "# Grounding report"
echo
echo "- Base: \`$1\`"
echo "- Changed files: **1**"
echo
echo "## Secret scan (gitleaks)"
echo "✅ No secrets detected in the diff."
echo "## Static analysis (semgrep)"
echo "⚠️  one lead"'

ground_ok() { fake_home "$REAL_PASS"; }

# ---------------------------------------------------------------- happy path
dir=$(make_repo); home=$(ground_ok)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "a real run records a marker" "Grounding marker recorded" "$out"
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "which lets the receipt be recorded" "receipt recorded" "$out"
grep -q '"grounding": "ok"' "$dir/.claude/state/audit-receipt.json" \
  && ok "the receipt records a real pass as ok" || bad "the receipt records a real pass as ok"

# ------------------------------------------------------- FALSE-GREEN #1: crash
# The run is `>"$log" 2>&1`, so a crashing pass fills the log with its own error
# message. "The log is non-empty" therefore passed, reported 0 leads, and wrote
# an `ok` marker.
home=$(fake_home '#!/usr/bin/env bash
echo "semgrep: command not found" >&2
exit 127')
dir=$(make_repo)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "FALSE-GREEN #1: a CRASHING pass is refused, not recorded" "no '# Grounding report' header" "$out"
[ -f "$dir/.claude/state/grounding.json" ] \
  && bad "no marker after a crashing pass" || ok "no marker after a crashing pass"

# ------------------------------------------------ FALSE-GREEN #2: forged marker
# An absent `skipped` key is falsy, so a hand-written marker — with no log on
# disk at all — read as `ok`. The honest hatch records `skipped`, which made
# faking it both easier AND cleaner-looking than the documented path.
dir=$(make_repo)
mkdir -p "$dir/.claude/state"
(
  cd "$dir" || exit 1
  python3 - <<'PYEOF'
import importlib.util, json, os
spec = importlib.util.spec_from_file_location("g", ".claude/hooks/guard-pr-audit.py")
g = importlib.util.module_from_spec(spec); spec.loader.exec_module(g)
head, tree = g.state(os.getcwd())
base = g.run(["git", "merge-base", "main", "HEAD"], os.getcwd()).strip()
json.dump({"head": head, "tree": tree, "base": base},
          open(".claude/state/grounding.json", "w"))
PYEOF
)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "FALSE-GREEN #2: a marker with no 'skipped' key is refused" "(missing)" "$out"

# A marker that is complete but whose log is gone: the digest cannot match.
dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
rm -f "$dir/.claude/state/grounding.log"
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "a marker whose log is gone is refused" "(stale)" "$out"

# ------------------------------------------------- a tool the diff NEEDS missing
# The diff is PHP, so gitleaks and semgrep are required unconditionally.
home=$(fake_home '#!/usr/bin/env bash
echo "# Grounding report"
echo "- Changed files: **1**"
echo "## Secret scan (gitleaks)"
echo "_skipped — gitleaks not installed. Install: \`brew install gitleaks\`_"
echo "## Static analysis (semgrep)"')
dir=$(make_repo)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "a pass missing a tool the diff NEEDS is refused" "this diff needs gitleaks" "$out"
[ -f "$dir/.claude/state/grounding.json" ] \
  && bad "no marker when a required tool was skipped" || ok "no marker when a required tool was skipped"

# ------------------------------------------------------------- format drift
# Reading a skipped tool as one that ran is the silent false green, so an
# unrecognised line that merely CONTAINS "skipped" must fail RED.
home=$(fake_home '#!/usr/bin/env bash
echo "# Grounding report"
echo "- Changed files: **1**"
echo "## Secret scan (gitleaks)"
echo "## Static analysis (semgrep)"
echo "_skipped, gitleaks, reasons_"')
dir=$(make_repo)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "a skip line the parser cannot read fails RED, not silently" "does not recognise" "$out"

# ...but the word "skipped" in QUOTED SOURCE is not a skip line. The pass echoes
# changed code, so a diff that merely contains the word tripped a hard refusal
# and made the gate unusable on the branch that adds it. Found by running the
# real thing on the real branch; no scratch repo produced it.
home=$(fake_home '#!/usr/bin/env bash
echo "# Grounding report"
echo "- Changed files: **1**"
echo "## Secret scan (gitleaks)"
echo "## Static analysis (semgrep)"
echo "## Heuristic pattern scan (changed files)"
echo "apps/api/tests/X.php:12: // the row that must be skipped by the filter"
echo "  && bad \"no marker when a required tool was skipped\""')
dir=$(make_repo)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "the word 'skipped' in quoted source is not a skip line" "Grounding marker recorded" "$out"

# The pass's own not-installed line for a tool this diff does NOT need is fine.
home=$(fake_home '#!/usr/bin/env bash
echo "# Grounding report"
echo "- Changed files: **1**"
echo "## Secret scan (gitleaks)"
echo "## Static analysis (semgrep)"
echo "## Dockerfile lint (hadolint)"
echo "_skipped — hadolint not installed. Install: \`brew install hadolint\`_"')
dir=$(make_repo)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "a tool the diff does NOT need may be missing" "Grounding marker recorded" "$out"

# -------------------------------------------------------------- scope guards
# The pass and this script must see the same diff.
home=$(fake_home '#!/usr/bin/env bash
echo "# Grounding report"
echo "- Changed files: **99**"
echo "## Secret scan (gitleaks)"
echo "## Static analysis (semgrep)"')
dir=$(make_repo)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "a pass that saw a different file count is refused" "resolved the range differently" "$out"

# Zero files means the BASE is wrong, and the message must say so rather than
# send the operator to debug the parser.
# Both sides must AGREE on zero for the base to be the answer — a stub claiming
# zero over a repo that has changes is a mismatch, and the mismatch message is
# the honest one because it can print both numbers.
home=$(fake_home '#!/usr/bin/env bash
echo "# Grounding report"
echo "- Changed files: **0**"')
dir=$(make_repo)
(cd "$dir" && git checkout -q -B feat/empty main)   # a branch with nothing on it
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "zero on BOTH sides blames the base, not the parser" "check the base ref" "$out"

# ...and a disagreement says so instead, with both numbers.
dir=$(make_repo)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "a count disagreement names both numbers" "resolved the range differently" "$out"

# A pass scoped to one commit must not certify the branch. It is now caught at
# WRITE time, by the file-count comparison, before a marker exists at all —
# earlier and for a better reason than the reader's scope check.
dir=$(make_repo); home=$(ground_ok)
wrong=$(cd "$dir" && git rev-parse HEAD)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh "$wrong" 2>&1)
check "a pass scoped to one commit is refused at write time" "resolved the range differently" "$out"

# And the reader's own scope check, driven directly: a marker that is otherwise
# perfect — right tree, right digest — but records a base nobody would compute.
# This is the path that catches a marker carried over from another range.
dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
(
  cd "$dir" || exit 1
  python3 - <<'PYEOF'
import json
m = json.load(open(".claude/state/grounding.json"))
m["base"] = "0" * 40          # a base no merge-base would ever return
json.dump(m, open(".claude/state/grounding.json", "w"))
PYEOF
)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "a marker recording a base nobody computed is out-of-scope" "(out-of-scope)" "$out"

# The correct base spelled as a REF must be accepted.
dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh main >/dev/null 2>&1)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "the correct base spelled as a REF is accepted" "receipt recorded" "$out"

# Writer and checker must PREFER the same ref when the two disagree.
dir=$(make_repo); home=$(ground_ok)
(
  cd "$dir" || exit 1
  git checkout -q main
  echo "<?php // only on origin" > apps/api/app/Remote.php
  git add -A >/dev/null 2>&1
  git -c user.email=t@t -c user.name=t commit -qm remote-only >/dev/null 2>&1
  git update-ref refs/remotes/origin/main HEAD
  git checkout -q feat/x
)
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "writer and checker prefer the same ref when origin/main and main differ" "receipt recorded" "$out"

# ------------------------------------------------------------------ the hatch
# It exists for a machine the pass CANNOT run on, so every case below runs with
# a $HOME that has no ground.sh. Using the real one would be asking for the
# hatch where the honest path works — which is now refused, one case down.
no_pass=$(mktemp -d "$tmproot/home.XXXXXX")

dir=$(make_repo)
out=$(cd "$dir" && HOME="$no_pass" REELMAP_SKIP_GROUNDING=1 bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "the skip is honoured and announced" "Grounding pass SKIPPED" "$out"
check "and it names the sentence the PR body needs" "Grounding pass skipped:" "$out"
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "a skipped pass still records a receipt" "receipt recorded" "$out"
grep -q '"grounding": "skipped"' "$dir/.claude/state/audit-receipt.json" \
  && ok "the skip is written into the receipt" || bad "the skip is written into the receipt"

# A hatch available where the honest path works is the path everyone takes.
dir=$(make_repo); home=$(ground_ok)
if command -v gitleaks >/dev/null 2>&1 && command -v semgrep >/dev/null 2>&1; then
  out=$(cd "$dir" && HOME="$home" REELMAP_SKIP_GROUNDING=1 bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
  check "the hatch is REFUSED where the pass can run" "the grounding pass CAN run here" "$out"
else
  echo "SKIP  [the hatch is REFUSED where the pass can run] (gitleaks/semgrep not installed here)"
fi

# Fail CLOSED when the skill is not installed, and name the hatch.
empty_home=$(mktemp -d "$tmproot/home.XXXXXX")
dir=$(make_repo)
out=$(cd "$dir" && HOME="$empty_home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "a missing grounding script refuses" "is not installed at" "$out"
check "and names the escape hatch rather than hiding it" "REELMAP_SKIP_GROUNDING=1" "$out"
[ -f "$dir/.claude/state/grounding.json" ] \
  && bad "no marker when the script is missing" || ok "no marker when the script is missing"

# -------------------------------------------------------------- staleness
dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
echo "<?php // later edit" > "$dir/apps/api/app/Thing.php"
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "a marker from BEFORE an edit is stale, and refused" "(stale)" "$out"

dir2=$(make_repo)
mkdir -p "$dir2/.claude/state"
cp "$dir/.claude/state/grounding.json" "$dir2/.claude/state/grounding.json"
out=$(cd "$dir2" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "a marker from another checkout is refused" "no grounding pass" "$out"

# ------------------------------------------------------------- exit codes
dir=$(make_repo)
(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none >/dev/null 2>&1)
rc=$?
[ "$rc" -eq 2 ] && ok "a refused receipt exits 2" || bad "a refused receipt exits 2" "got $rc"

dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
rc=$?
[ "$rc" -eq 0 ] && ok "a successful marker run exits 0" || bad "a successful marker run exits 0" "got $rc"

# A lead is not a finding, but it is a thing somebody must have looked at. The
# count was recorded and read by nothing until this refusal existed.
dir=$(make_repo); home=$(ground_ok)   # the stub emits one ⚠️
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed --declines none 2>&1)
check "a receipt with no note is refused when the pass raised leads" "carries no note" "$out"
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "checked all 1" --declines none 2>&1)
check "and accepted once the note says what they were" "receipt recorded" "$out"

# --- the declines field ------------------------------------------------------
#
# CLAUDE.md §4 says every finding is disposed of before the receipt — fixed,
# declined, or bounded — and that a 🔴/🟡 needs an owner waiver to be declined.
# Until this refusal existed that rule lived only in prose, and prose is what
# T-158 spent nineteen commits proving is not enough: the receipt hashes HEAD and
# the tree and knows nothing about findings, so a declined blocker left no trace.
#
# What this CAN enforce is that the question was answered. Same value, and the
# same honest limit, as approve.sh naming the axes: it does not prove a decline
# was justified, it makes omitting one a decision rather than an oversight.
dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" 2>&1)
check "a receipt with no --declines is refused" "no --declines" "$out"

out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "and accepted when it says nothing was declined" "receipt recorded" "$out"
d_json=$(cd "$dir" && python3 -c 'import json;print(json.load(open(".claude/state/audit-receipt.json"))["declines"])' 2>&1)
check "the answer reaches the receipt" "none" "$d_json"

out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines "T-172: two clocks, owner waived" 2>&1)
check "a described decline is accepted" "receipt recorded" "$out"
d_json=$(cd "$dir" && python3 -c 'import json;print(json.load(open(".claude/state/audit-receipt.json"))["declines"])' 2>&1)
check "and is recorded verbatim, not as a boolean" "owner waived" "$d_json"

# An empty string is the shape an agent reaches for when it wants the field gone.
# Refused at PARSE time now, not at the requirement check — an empty value is a
# typo wherever it appears, so the earlier and more specific reason is the right
# one. (This case predates the parse loop; it used to fall through to the
# requirement's generic refusal.)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines "" 2>&1)
check "an empty --declines is refused, not treated as none" "needs a value" "$out"

# THE BYPASS, found by both seats and reproduced in a scratch repo: the first
# version scanned "$@" for the flag while still reading the note from `$2`, so
# `findings-fixed --declines none` made the flag its own note — the leads refusal
# was satisfied by the string "--declines" and the receipt recorded it AS the
# note. A new gate that switched off the gate beside it.
#
# The assertion names the refusal REASON, not just the refusal: the two are now
# adjacent, and a reason-blind test would pass against either.
dir=$(make_repo); home=$(ground_ok)   # the stub emits one ⚠️, so a note is required
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed --declines none 2>&1)
check "the flag cannot stand in for the note" "carries no note" "$out"

# Parse cases that used to be accepted silently.
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none --declines "T-9: waived" 2>&1)
check "--declines twice is refused, not first-wins" "given twice" "$out"
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines --force 2>&1)
check "a flag-shaped value is refused, not recorded as the answer" "needs a value" "$out"
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" "extra" --declines none 2>&1)
check "a second positional is refused" "unexpected argument" "$out"
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --oops --declines none 2>&1)
check "an unknown option is refused" "unknown option" "$out"

# The flag-shape guard matched only `--*`, so a single-dash value walked straight
# in and was recorded AS the disposition — the exact thing its own comment says it
# prevents.
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines -n 2>&1)
check "a single-dash value is refused too, not recorded as the answer" "needs a value" "$out"

# `--declines=none` is the likeliest typo, and "unknown option" told a BLOCKED
# agent the flag does not exist — sending it hunting for another name. The reason
# has to name the form that works.
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines=none 2>&1)
check "--declines=none names the two-word form" "two words" "$out"
# A note that merely CONTAINS the flag name is a note, not a flag.
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "about --declines" --declines none 2>&1)
check "a note mentioning the flag is still a note" "receipt recorded" "$out"

# `clean` is NOT exempt: nothing ties the verdict to a finding, so exempting it
# made "type clean" the one-word way past this refusal.
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh clean "n" 2>&1)
check "clean is refused without --declines too" "no --declines" "$out"
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh clean "n" --declines none 2>&1)
check "and accepted with it" "receipt recorded" "$out"

# docs-only seats nobody, so there are no findings to dispose of. `make_repo`
# always builds a code diff, so the docs case is made by replacing it.
dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && git rm -q apps/api/app/Thing.php && printf '# d\n' > README.md \
   && git add -A && git -c user.email=t@t -c user.name=t commit -qm docs) >/dev/null 2>&1
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh docs-only 2>&1)
check "docs-only needs no --declines" "receipt recorded" "$out"

# Cheap refusals before expensive ones. The requirement depends only on $verdict,
# and behind the selector and check-grounding.py a missing flag cost a full
# gitleaks/semgrep run before anything said why. A repo with NO marker at all
# must answer about the flag first.
dir=$(make_repo)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" 2>&1)
check "a missing --declines is refused before the grounding check" "no --declines" "$out"
printf '%s' "$out" | grep -q "no grounding pass" \
  && bad "the cheap refusal comes first" "also ran the grounding check: $out" \
  || ok "the cheap refusal comes first"

# `[ $# -gt 0 ] && shift` guards the zero-argument path. Written as a bare
# `shift` — the obvious simplification — set -e kills the script with no output
# and exit 1, and the usage text below it becomes unreachable.
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh 2>&1); rc=$?
check "no arguments at all prints the usage" "usage: record-receipt.sh" "$out"
check "and the usage names the flag" "--declines" "$out"
[ "$rc" -eq 2 ] && ok "no arguments exits 2, not 1" || bad "no arguments exits 2, not 1" "got $rc"

# Running the marker must not CHANGE the diff it is describing. Importing the
# hook writes __pycache__ beside it — an untracked file under .claude/, which
# the receipt's self-mod check counts — so the marker run would make every diff
# look like it touched the guard path. Fixed once, lost in a rewrite, and caught
# the second time only incidentally by another suite. Not incidental now.
dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
stray=$(cd "$dir" && git status --porcelain | grep -c "__pycache__")
[ "$stray" -eq 0 ] \
  && ok "the marker run leaves no bytecode in .claude/" \
  || bad "the marker run leaves no bytecode in .claude/" "found $stray"

# ------------------------------------------------- against the REAL pass
# Every case above stubs ground.sh, so the parser is checked against a mock of
# itself and agrees by construction. That suite is structurally incapable of
# catching a disagreement between this script and the real pass — and one had
# already shipped: ground.sh drops paths failing `[ -e ]`, this script did not,
# so a file deleted in the working tree made the two counts differ and refused a
# legitimate branch while blaming the base ref.
real_ground="$HOME/.claude/skills/coderabbit/scripts/ground.sh"
if [ -f "$real_ground" ] && command -v gitleaks >/dev/null 2>&1 && command -v semgrep >/dev/null 2>&1; then
  dir=$(make_repo)
  out=$(cd "$dir" && bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
  check "the REAL pass and this script agree on the file set" "Grounding marker recorded" "$out"

  # The divergence that shipped: in the diff, absent from disk.
  dir=$(make_repo)
  (cd "$dir" && echo "<?php" > apps/api/app/Gone.php && git add -A >/dev/null 2>&1      && git -c user.email=t@t -c user.name=t commit -qm gone >/dev/null 2>&1 && rm apps/api/app/Gone.php)
  out=$(cd "$dir" && bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
  check "a file deleted in the working tree does not break the count" "Grounding marker recorded" "$out"

  # And a base file removed from disk must not be reported as a bad base ref.
  dir=$(make_repo)
  (cd "$dir" && rm apps/api/app/Seed.php)
  out=$(cd "$dir" && bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
  printf '%s' "$out" | grep -q "check the base ref"     && bad "a deleted file is not blamed on the base ref" "$out"     || ok "a deleted file is not blamed on the base ref"
else
  echo "SKIP  [the REAL pass agrees with this script] (ground.sh or its scanners not installed)"
fi

# A pass that emits headers and nothing else: the shape a stub had, and the
# shape "no skip line means it ran" accepted.
home=$(fake_home '#!/usr/bin/env bash
echo "# Grounding report"
echo "- Changed files: **1**"
echo "## Secret scan (gitleaks)"')
dir=$(make_repo)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "a required tool with NO section at all is refused" "no section for them at all" "$out"

# The three mutations a review found green. Each needs a case that is not.
#
# 1. Every scratch repo had a clean tree, so dropping the working-tree half of
#    the file list changed nothing — the regression the comment says shipped once.
#    An UNTRACKED file is invisible to both sides (neither uses --others), so
#    it cannot show the difference; a MODIFIED tracked file can.
dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && echo "<?php // edited, not committed" > apps/api/app/Seed.php)
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "an uncommitted EDIT counts toward the file set" "resolved the range differently" "$out"

# 2. The diff was always one .php file, so the per-filetype derivation was
#    never exercised: deleting it whole left the suite green.
dir=$(make_repo)
(cd "$dir" && printf '#!/usr/bin/env bash\necho hi\n' > scripts.sh && git add -A >/dev/null 2>&1 \
   && git -c user.email=t@t -c user.name=t commit -qm sh >/dev/null 2>&1)
home=$(fake_home '#!/usr/bin/env bash
echo "# Grounding report"
echo "- Changed files: **2**"
echo "## Secret scan (gitleaks)"
echo "## Static analysis (semgrep)"')
out=$(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh 2>&1)
check "a .sh in the diff REQUIRES the shell linter" "needs shellcheck" "$out"

# The marker's own `required_tools` is a claim by the writer. Both readers took
# it, so setting it to [] beside a log saying gitleaks was not installed
# satisfied every check. They derive it now.
dir=$(make_repo); home=$(ground_ok)
(cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
(
  cd "$dir" || exit 1
  printf '%s\n' '_skipped — gitleaks not installed. Install: x_' >> .claude/state/grounding.log
  python3 - <<'PYEOF'
import importlib.util, json, os
spec = importlib.util.spec_from_file_location("g", ".claude/hooks/guard-pr-audit.py")
g = importlib.util.module_from_spec(spec); spec.loader.exec_module(g)
m = json.load(open(".claude/state/grounding.json"))
m["required_tools"] = []                       # the writer's claim, emptied
m["tools_skipped"] = []
m["digest"] = g.grounding_digest(".claude/state/grounding.log")   # kept consistent
json.dump(m, open(".claude/state/grounding.json", "w"))
PYEOF
)
out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed "n" --declines none 2>&1)
check "an emptied required_tools does not hide a skipped scanner" "(out-of-scope)" "$out"

# A lead count that cannot be trusted must fail CLOSED, not become 0.
# Python literals — `null` is JSON, and writing it here made the edit raise, so
# the marker kept its real count and the case passed for the wrong reason.
for bad_leads in 'None' '"many"' '-3' 'True'; do
  dir=$(make_repo); home=$(ground_ok)
  (cd "$dir" && HOME="$home" bash .claude/skills/audit-agency/run-grounding.sh >/dev/null 2>&1)
  (cd "$dir" && python3 -c "
import json
m = json.load(open('.claude/state/grounding.json'))
m['leads'] = $bad_leads
json.dump(m, open('.claude/state/grounding.json','w'))")
  out=$(cd "$dir" && bash .claude/skills/audit-agency/record-receipt.sh findings-fixed --declines none 2>&1)
  check "a lead count of $bad_leads is refused, not read as zero" "not a whole number" "$out"
done

[ $fails -eq 0 ] && echo "ALL PASS" || echo "$fails FAILED"
exit $((fails > 0))
