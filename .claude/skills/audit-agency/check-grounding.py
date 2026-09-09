#!/usr/bin/env python3
"""Say whether the grounding pass ran against the CURRENT tree.

Prints one word on stdout: `ok`, `skipped`, `stale`, `out-of-scope`, or
`missing`. `record-receipt.sh` refuses on anything but the first two.

Separate from record-receipt.sh so the check has a name a test can call, and so
the tree hash and the log digest are computed by importing the hook that OWNS
them rather than by second copies of either calculation.

What this proves, stated plainly so nobody later mistakes provenance for
integrity: the marker corresponds to this commit, this tree and this base, it
was written alongside a log it still matches, and the tools this diff needed
were not reported missing. It does NOT prove the pass actually executed — a
hand-written marker plus a hand-written log passes. The threat model is
`guard-pr-audit.py`'s: a busy agent taking a shortcut, not someone attacking
their own repository.
"""

# `str | None` in an annotation is evaluated at def time before 3.10, and
# /usr/bin/python3 on macOS is 3.9 — the module would raise before main() exists,
# record-receipt.sh would read that as `missing`, and the only visible way out of
# the resulting loop is the escape hatch. Both sibling scripts carry this.
from __future__ import annotations

import importlib.util
import json
import os
import pathlib
import re
import sys

MARKER = ".claude/state/grounding.json"
LOG = ".claude/state/grounding.log"


def guard_module():
    """The hook that owns the tree hash, the git runner and the log digest."""
    spec = importlib.util.spec_from_file_location("guard", ".claude/hooks/guard-pr-audit.py")
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load .claude/hooks/guard-pr-audit.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def base_ref(guard) -> str | None:
    """The merge base the grounding pass should have been scoped to.

    None when neither ref resolves. The caller then reports `stale` — fail
    closed, like every other unknown here. An earlier version skipped the check
    instead, justified by "run-grounding.sh already refuses"; that is false for
    the path that matters, because with an explicit base argument
    run-grounding.sh never computes a merge base at all. What actually refuses
    first today is record-receipt.sh, a different script — a guarantee that
    disappears the moment this checker gains a second caller.
    """
    for ref in ("origin/main", "main"):
        out = guard.run(["git", "merge-base", ref, "HEAD"], os.getcwd()).strip()
        if out:
            return out
    return None


def main() -> int:
    try:
        guard = guard_module()
    except (OSError, RuntimeError):
        print("missing")
        return 0

    head, tree = guard.state(os.getcwd())
    if not head:
        print("missing")
        return 0

    path = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else MARKER)
    try:
        marker = json.loads(path.read_text())
    except (OSError, ValueError):
        print("missing")
        return 0

    # PRESENT, not merely truthy. An absent key is falsy, so reading it as "not
    # skipped" made a hand-written marker with no log at all report `ok` — one
    # of the three false greens this gate was rebuilt to close.
    if "skipped" not in marker or not isinstance(marker["skipped"], bool):
        print("missing")
        return 0

    # Both, not either: HEAD alone misses an uncommitted edit, and the tree alone
    # misses an amend that left the content identical.
    if marker.get("tree") != tree or marker.get("head") != head:
        print("stale")
        return 0

    expected = base_ref(guard)
    if expected is None or marker.get("base") != expected:
        # A distinct word: "stale" (the code moved) and "out-of-scope" (the code
        # did not, but the pass covered a different range) need different fixes,
        # and the refusal text is the only place an operator learns which.
        print("out-of-scope")
        return 0

    # The log must still be the one the marker was written beside. An empty
    # digest means the log could not be read AT ALL — refuse rather than let two
    # empty strings compare equal.
    digest = marker.get("digest")
    if not digest or digest != guard.grounding_digest(LOG):
        print("stale")
        return 0

    if marker["skipped"]:
        print("skipped")
        return 0

    # Re-derived from the LOG, not from the marker's own two lists. Intersecting
    # `required_tools` with `tools_skipped` read both operands out of the same
    # file the writer wrote, so it could never disagree with the writer — a
    # marker claiming `"required_tools": []` beside `"tools_skipped":
    # ["gitleaks"]` returned ok. The log is digest-bound (checked above), so
    # scanning it is an independent reading of the same evidence.
    try:
        log_text = pathlib.Path(LOG).read_text(errors="replace")
    except OSError:
        print("stale")
        return 0
    skipped_in_log = set(re.findall(r"^_skipped — (\S+) not installed", log_text, re.M))
    if set(marker.get("required_tools") or []) & skipped_in_log:
        print("out-of-scope")
        return 0

    print("ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
