#!/usr/bin/env bash
# Run the /coderabbit grounding pass and leave a marker keyed to THIS tree.
#
# Why this exists (T-156): the review step in CLAUDE.md §2 is `/coderabbit`,
# whose Phase 3.5 seats the audit lanes. Seating the lanes ALONE and calling it
# a review skips the half that is a script and cannot be talked out of a
# finding — gitleaks, semgrep, osv-scanner, actionlint, hadolint, plus
# ShellCheck and the heuristics for wrong-reason assertions and hand-maintained
# mirrors. A whole session did exactly that: three seats, five commits, no
# grounding pass, and two 🔴s found only because a human-shaped reader looked
# twice. `record-receipt.sh` refuses without the marker this writes.
#
# (Do not start a comment line with the lowercase name of the shell linter: it
# is read as a directive, and an unparseable one makes that tool skip the WHOLE
# file — which is how this script went unlinted until the grounding pass ran.)
#
# WHAT THE MARKER ATTESTS, and what it does not. It records which tools THIS
# DIFF required and whether any of them were missing — not merely that a script
# wrote a file. The first version recorded "the log is non-empty", which a
# CRASHING pass satisfies with its own error message, and counted sections,
# which ~14 unconditional grep heuristics satisfy on a machine with no scanner
# installed at all. The threat model is the one `guard-pr-audit.py` states: a
# busy agent taking a shortcut, not someone attacking their own repository.
# Nothing here survives a determined forger, and it is not meant to.
#
# One more limit worth naming: a tool that is INSTALLED but fails at runtime
# (semgrep unable to fetch its rules offline) prints a findings line with
# nothing under it, and emits no skip line. The marker cannot tell that from a
# real run. It certifies that the tools the diff needed were present, not that
# each produced a meaningful result.
set -uo pipefail

top="$(git rev-parse --show-toplevel 2>/dev/null)" || {
  echo "refused: not inside a git repository" >&2
  exit 2
}
cd "$top" || exit 2

ground="$HOME/.claude/skills/coderabbit/scripts/ground.sh"

# RESOLVED to a merge-base SHA, whatever spelling arrived. The marker records
# this value and `check-grounding.py` compares it against a recomputed SHA — so
# recording `$1` verbatim made `run-grounding.sh main` write a marker that could
# never match, and the receipt then told the operator to run the command they
# had just run. The type of `base` is decided here, once, at the writer.
base="$(git merge-base "${1:-origin/main}" HEAD 2>/dev/null \
  || git merge-base "${1:-main}" HEAD 2>/dev/null)" || {
  echo "refused: no merge base for '${1:-origin/main or main}' — nothing to scope the grounding pass to" >&2
  exit 2
}

# The marker lives in .claude/state, and the tree hash the guard computes counts
# UNTRACKED files — so if that directory is ever un-ignored, writing the marker
# changes the tree and instantly invalidates the marker just written. Refuse
# with the reason rather than leave a loop nobody can exit.
if ! git check-ignore -q .claude/state/ 2>/dev/null; then
  echo "refused: .claude/state/ is not git-ignored — the marker would invalidate itself (the tree hash counts untracked files)." >&2
  exit 2
fi

mkdir -p .claude/state
log=.claude/state/grounding.log

if [ ! -f "$ground" ] && [ "${REELMAP_SKIP_GROUNDING:-}" != 1 ]; then
  cat >&2 <<MSG
refused: the grounding pass is not installed at
  $ground

It ships with the user-level /coderabbit skill. Install it, or — with the
owner's approval — set REELMAP_SKIP_GROUNDING=1, which records the skip IN the
receipt rather than hiding it. If you use the hatch, the PR body must say so:

  "Grounding pass skipped: <reason>. The scripted review half did not run."
MSG
  exit 2
fi

# The hatch is for a machine where the pass CANNOT run. If the script and the
# two unconditional scanners are all present, it can — and a hatch available
# where the honest path works is the path everyone takes. Refuse it there.
if [ "${REELMAP_SKIP_GROUNDING:-}" = 1 ] && [ -f "$ground" ] \
   && command -v gitleaks >/dev/null 2>&1 && command -v semgrep >/dev/null 2>&1; then
  cat >&2 <<'MSG'
refused: REELMAP_SKIP_GROUNDING=1, but the grounding pass CAN run here — the
script and both unconditional scanners (gitleaks, semgrep) are installed.

The hatch is for a machine the pass cannot run on. Run it:
  .claude/skills/audit-agency/run-grounding.sh
MSG
  exit 2
fi

skipped=false
if [ "${REELMAP_SKIP_GROUNDING:-}" = 1 ]; then
  skipped=true
  echo "grounding SKIPPED by REELMAP_SKIP_GROUNDING=1" >"$log"
  cat >&2 <<'MSG'
⚠️  Grounding pass SKIPPED. The receipt records it and the push guard repeats
   it. Paste this into the PR body, or the skip is undocumented:

     "Grounding pass skipped: <reason>. The scripted review half did not run."
MSG
else
  echo "Running the grounding pass against ${base:0:12} …" >&2
  # Never aborts on findings by design: every ⚠️ is a lead to verify, not a
  # verdict, so a non-zero exit here would be wrong. Failing to RUN is a
  # different thing, and the parser below is what tells them apart.
  bash "$ground" "$base" >"$log" 2>&1
fi

# PYTHONDONTWRITEBYTECODE: importing the hook writes __pycache__/*.pyc beside
# it — an UNTRACKED file under .claude/, which the receipt's self-mod check
# counts. Without this, running the marker script makes the diff look like it
# touched the guard path. Fixed once already and lost in a rewrite; the
# select-lanes suite is what caught it the second time.
SKIPPED="$skipped" BASE="$base" LOG="$log" PYTHONDONTWRITEBYTECODE=1 python3 - <<'PY'
import fnmatch, importlib.util, json, os, pathlib, re
from datetime import datetime, timezone

# The hook owns the tree hash AND the digest, imported rather than
# reimplemented: two copies of either calculation drift, and drift here means a
# marker that matches when it should not.
spec = importlib.util.spec_from_file_location("guard", ".claude/hooks/guard-pr-audit.py")
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)

head, tree = guard.state(os.getcwd())
if not head:
    raise SystemExit("not a git repository, or git failed — no grounding marker written")

log = pathlib.Path(os.environ["LOG"])
text = log.read_text(errors="replace")
base = os.environ["BASE"]
skipped = os.environ["SKIPPED"] == "true"

# Asked of the hook, which owns this rule now: the marker's own `required_tools`
# is a claim by this script, and both readers used to take it at face value.
# They derive it too, from the diff and the digest-bound log.
files = guard.changed_files(os.getcwd(), base)
required = guard.required_tools(files)

result = {
    "head": head,
    "tree": tree,
    "base": base,
    "skipped": skipped,
    "changed_files": len(files),
    "required_tools": sorted(required),
    "tools_skipped": [],
    "leads": len(re.findall(r"^⚠", text, re.M)),  # anchored: the footer legend mentions ⚠️ too
    "digest": guard.grounding_digest(log),
    "ran_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
}

if not skipped:
    # The pass's own header, so a wholesale format change is caught HERE rather
    # than inferred from a section count that happens to be zero.
    if "# Grounding report" not in text:
        raise SystemExit(
            "refused: the grounding output has no '# Grounding report' header — it did not\n"
            "run, or its format changed. No marker written."
        )

    m = re.search(r"^- Changed files: \*\*(\d+)\*\*", text, re.M)
    if not m:
        raise SystemExit("refused: the grounding output states no changed-file count. No marker written.")
    seen = int(m.group(1))
    # The mismatch check FIRST when we have files of our own: it can print both
    # numbers, while the zero branch can only blame the base — and blaming the
    # base for a disagreement is how an operator ends up debugging the wrong
    # thing. Zero on both sides is the only case the base is really the answer.
    if seen != len(files):
        raise SystemExit(
            f"refused: the grounding pass saw {seen} changed file(s) and this script sees "
            f"{len(files)}.\nOne of us resolved the range differently — no marker written."
        )
    if seen == 0:
        raise SystemExit(
            f"refused: the grounding pass saw NO changed files against {base[:12]} —\n"
            "check the base ref, not the parser. No marker written."
        )

    # Drift must fail RED — reading a skipped tool as one that ran is the silent
    # false green. But the FAILURE condition has to be anchored to the same
    # prefix as the success condition, not to the word "skipped" anywhere in the
    # output: the pass QUOTES changed source lines, so a diff that merely
    # contains the word (this branch's own tests do) tripped a hard refusal and
    # made the gate unusable on the very change that adds it. Found by running
    # it on this branch — no scratch repo would have.
    for line in text.splitlines():
        if not line.startswith("_skipped"):
            continue
        if re.match(r"^_skipped — (\S+) not installed", line):
            continue
        raise SystemExit(
            f"refused: a skip line the parser does not recognise:\n  {line.strip()}\n"
            "It cannot tell a missing tool from one that ran. No marker written."
        )

    result["tools_skipped"] = sorted(guard.skipped_tools(text))

    # A required tool must have a SECTION in the report, not merely no skip
    # line. Absence of a skip line was read as "it ran", so a pass emitting a
    # header and nothing else satisfied every check — which is precisely what
    # the stub in this gate's own test suite does.
    silent = sorted(t for t in required if guard.SECTION_FOR.get(t, t) not in text)
    if silent:
        raise SystemExit(
            "refused: this diff needs " + ", ".join(silent) + ", and the grounding output has\n"
            "no section for them at all — the pass did not cover what the diff calls for.\n"
            "No marker written."
        )

    missing = sorted(required & set(result["tools_skipped"]))
    if missing:
        raise SystemExit(
            "refused: this diff needs " + ", ".join(missing) + ", and the grounding pass\n"
            "reported them not installed. Install them and re-run — a pass that skipped the\n"
            "tools the diff calls for is the false green this marker exists to prevent.\n"
            "No marker written."
        )

pathlib.Path(".claude/state/grounding.json").write_text(json.dumps(result, indent=2) + "\n")
what = "SKIPPED" if skipped else f"{result['leads']} lead(s)"
print(f"✅ Grounding marker recorded for {head[:8]} ({what}).")
PY
