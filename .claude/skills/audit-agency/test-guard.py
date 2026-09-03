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

import json
import os
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

    # A directory that is not a git repo must also fail closed.
    with tempfile.TemporaryDirectory() as tmp:
        got = decision(PUSH, project_dir=tmp)
        ok = got == "DENY"
        print(f"{'PASS' if ok else 'FAIL'}  {'non-repo fails closed':38s} want=DENY  got={got}")
        if not ok:
            failures.append("non-repo")

    print()
    print("ALL PASS" if not failures else f"FAILURES: {failures}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
