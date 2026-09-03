#!/usr/bin/env python3
"""Behaviour tests for .claude/hooks/guard-pr-audit.py.

Run: python3 .claude/skills/audit-agency/test-guard.py

Every DENY case below is a way the gate must not be talked out of a decision,
and the three marked `[audit]` are bypasses the agency panel actually drove
through the first, regex-based version of this hook — two of them using nothing
more exotic than a flag that takes a value. A gate nobody has watched fail is
worth as little as the bug it was written to catch, so they stay here as
regressions rather than as a paragraph claiming they were fixed.

The command strings are ASSEMBLED from fragments so that this file does not
itself trip the user-level gate that scans raw command text.
"""

import importlib.util
import json
import os
import pathlib
import subprocess
import sys
import tempfile

# .../.claude/skills/audit-agency/test-guard.py -> repo root is four levels up.
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
HOOK = os.path.join(ROOT, ".claude", "hooks", "guard-pr-audit.py")

PUSH = "git" + " push"
GHPR = "gh" + " pr "


def decision(cmd, env_extra=None, project_dir=ROOT):
    env = dict(os.environ, CLAUDE_PROJECT_DIR=project_dir)
    env.pop("REELMAP_SKIP_AUDIT", None)
    env.update(env_extra or {})
    p = subprocess.run(
        [sys.executable, HOOK],
        input=json.dumps({"tool_input": {"command": cmd}}),
        capture_output=True,
        text=True,
        env=env,
    )
    if p.returncode != 0:
        return f"CRASH: {p.stderr.strip()[:200]}"
    return "DENY" if p.stdout.strip() else "ALLOW"


# (name, command, expected)
CASES = [
    # --- must be blocked -------------------------------------------------
    ("plain push", PUSH + " origin main", "DENY"),
    ("PR open", GHPR + "create --title x", "DENY"),
    ("PR merge", GHPR + "merge 201", "DENY"),
    ("PR ready", GHPR + "ready 201", "DENY"),
    ("push after another command", "make build && " + PUSH, "DENY"),
    ("push with force flag", PUSH + " --force-with-lease origin main", "DENY"),
    ("[audit] flag taking a value", "git -C /tmp/repo push origin main", "DENY"),
    ("[audit] gh with --repo", "gh --repo owner/name " + "pr create", "DENY"),
    ("[audit] quote splicing", "git pu''sh origin main", "DENY"),
    ("quote splicing, double", 'git pu""sh origin main', "DENY"),
    ("push in a later segment", "cd /tmp ; echo hi ; " + PUSH, "DENY"),
    # [audit2] found by the second review round, all reproduced before fixing.
    ("[audit2] newline-joined after cd", "cd /tmp\n" + PUSH + " origin main", "DENY"),
    ("[audit2] newline-joined after any cmd", "make build\n" + PUSH, "DENY"),
    ("[audit2] multi-line with blank lines", "echo one\n\n" + PUSH + "\n", "DENY"),
    ("[audit2] trailing cd must not redirect", PUSH + " origin main ; cd /tmp", "DENY"),
    # --- must be allowed -------------------------------------------------
    ("unrelated command", "ls -la", "ALLOW"),
    ("pushd is not push", "pushd /tmp", "ALLOW"),
    ("git status", "git status --porcelain", "ALLOW"),
    ("gh pr view is read-only", "gh " + "pr view 201", "ALLOW"),
    ("gh pr list is read-only", "gh " + "pr list", "ALLOW"),
    ("the word inside a quoted argument", "git commit -m 'about " + PUSH + " and " + GHPR + "create'", "ALLOW"),
    ("echoing the word", "echo " + PUSH, "ALLOW"),
    ("a heredoc body mentioning it", "cat > /tmp/n.md <<'EOF'\nwe should " + PUSH + " later\nEOF", "ALLOW"),
    ("grepping for it", "grep -rn 'push' .claude/", "ALLOW"),
    # [audit3] Unparseable quoting fails CLOSED — but only for the GATED verbs.
    # The first fallback matched any `gh … pr …`, so a PR COMMENT whose body
    # contained shell-escaped quotes was denied. Found by the gate denying its
    # author mid-merge.
    ("[audit3] unparseable + comment", GHPR + "comment 201 --body 'it'\"'\"'s fine", "ALLOW"),
    ("[audit3] unparseable + merge", GHPR + "merge 201 --body 'it'\"'\"'s fine", "DENY"),
    ("[audit3] unparseable + push", PUSH + " --force 'unclosed", "DENY"),
    # [audit4] A backslash-newline is a line CONTINUATION — the shell joins the
    # lines into one command. Narrowing the fallback in [audit3] also bounded it
    # to a single line, which reopened the hole: with the verb one line below its
    # program, an unparseable gated command stopped matching and was ALLOWED.
    # Two reviewers found it independently, 20 minutes after it was written.
    ("[audit4] continuation push", "git \\\npush origin main", "DENY"),
    ("[audit4] continuation gh pr", "gh pr \\\ncreate --title x", "DENY"),
    ("[audit4] unparseable across a continuation", "git \\\npush origin main --tag 'unclosed", "DENY"),
    ("[audit4] continuation, ungated verb", "gh pr \\\ncomment 201 --body-file /tmp/x", "ALLOW"),
]


def main():
    failures = []

    for name, cmd, want in CASES:
        got = decision(cmd)
        ok = got == want
        print(f"{'PASS' if ok else 'FAIL'}  {name:38s} want={want:5s} got={got}")
        if not ok:
            failures.append(name)

    # The escape hatch must actually open the gate.
    got = decision(PUSH, {"REELMAP_SKIP_AUDIT": "1"})
    ok = got == "ALLOW"
    print(f"{'PASS' if ok else 'FAIL'}  {'escape hatch honored':38s} want=ALLOW got={got}")
    if not ok:
        failures.append("escape hatch")

    # A bad CLAUDE_PROJECT_DIR must fail CLOSED, not wave the push through.
    got = decision(PUSH, project_dir="/nonexistent/path/xyz")
    ok = got == "DENY"
    print(f"{'PASS' if ok else 'FAIL'}  {'bad project dir fails closed':38s} want=DENY  got={got}")
    if not ok:
        failures.append("bad project dir")

    # [audit2] A trailing `cd` into a repo that DOES hold a valid receipt must
    # not launder a push in a repo that does not. target_dir() used to scan every
    # segment, so a follow-up `cd` decided where the receipt was looked for.
    #
    # Built hermetically — a real temp repo carrying a REAL matching receipt —
    # because the first version of this case pointed at this repo and passed
    # either way: during development this tree is dirty, so its receipt is stale
    # and the answer is DENY with or without the bug. A test that cannot fail is
    # the thing this suite exists to catch, and it caught itself.
    with tempfile.TemporaryDirectory() as clean, tempfile.TemporaryDirectory() as bare:
        subprocess.run(["git", "init", "-q", clean], check=True)
        subprocess.run(["git", "-C", clean, "config", "user.email", "t@t"], check=True)
        subprocess.run(["git", "-C", clean, "config", "user.name", "t"], check=True)
        (pathlib.Path(clean) / "f.txt").write_text("hello\n")
        subprocess.run(["git", "-C", clean, "add", "-A"], check=True)
        subprocess.run(["git", "-C", clean, "commit", "-qm", "init"], check=True)

        spec = importlib.util.spec_from_file_location("guard", HOOK)
        guard = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(guard)
        head, tree = guard.state(clean)
        rec = pathlib.Path(clean) / ".claude" / "state"
        rec.mkdir(parents=True)
        (rec / "audit-receipt.json").write_text(
            json.dumps({"head": head, "tree": tree, "verdict": "clean"})
        )

        # Sanity: the receipt IS valid there, so a push judged against `clean`
        # would be allowed. Without this the next assertion proves nothing.
        got = decision(PUSH, project_dir=clean)
        ok = got == "ALLOW"
        print(f"{'PASS' if ok else 'FAIL'}  {'[audit2] control: valid receipt allows':38s} want=ALLOW got={got}")
        if not ok:
            failures.append("laundering control")

        # The real case: pushing from a receipt-less repo, with a trailing cd
        # into the one that has a receipt, must still be denied.
        got = decision(PUSH + " origin main ; cd " + clean, project_dir=bare)
        ok = got == "DENY"
        print(f"{'PASS' if ok else 'FAIL'}  {'[audit2] trailing cd cannot launder':38s} want=DENY  got={got}")
        if not ok:
            failures.append("trailing cd launder")

    # [audit2] A non-dict payload must not crash the hook into silence (which the
    # harness reads as allow).
    p = subprocess.run(
        [sys.executable, HOOK], input=json.dumps(["not", "a", "dict"]),
        capture_output=True, text=True, env=dict(os.environ, CLAUDE_PROJECT_DIR=ROOT),
    )
    ok = p.returncode == 0
    print(f"{'PASS' if ok else 'FAIL'}  {'[audit2] non-dict payload no crash':38s} want=exit0 got={p.returncode}")
    if not ok:
        failures.append("non-dict payload")

    # A directory that is not a git repo must also fail closed.
    with tempfile.TemporaryDirectory() as tmp:
        got = decision(PUSH, project_dir=tmp)
        ok = got == "DENY"
        print(f"{'PASS' if ok else 'FAIL'}  {'non-repo fails closed':38s} want=DENY  got={got}")
        if not ok:
            failures.append("non-repo")

    # [audit4] The continuation JOIN, asserted structurally rather than through
    # the allow/deny cases above — those pass either way, because the
    # unparseable fallback catches a continuation too. The difference the join
    # makes is that the command gets PARSED (so `classify` and `target_dir` see
    # real argv) instead of falling back to a crude regex, and only segments()
    # shows that.
    spec = importlib.util.spec_from_file_location("guard_seg", HOOK)
    guard = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(guard)
    try:
        got_segs = guard.segments("git \\\npush origin main")
    except Exception as exc:  # noqa: BLE001 — a raise here IS the regression
        # Without the join, shlex hits the trailing backslash and raises
        # "No escaped character". Caught so this reports as a failed case
        # rather than a traceback that ends the run before later cases execute.
        got_segs = f"raised {type(exc).__name__}: {exc}"
    ok = got_segs == [["git", "push", "origin", "main"]]
    print(f"{'PASS' if ok else 'FAIL'}  {'[audit4] continuation is parsed, not punted':38s} got={got_segs}")
    if not ok:
        failures.append("continuation parsing")

    print()
    print("ALL PASS" if not failures else f"FAILURES: {failures}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
