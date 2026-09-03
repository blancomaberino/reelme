#!/usr/bin/env python3
"""PreToolUse(Bash) — nothing reaches the repo without an agency audit of the
code as it stands right now.

WHY. The gates prove the code does what it says; the line-by-line review reads
the diff. Neither asks whether a user can reach the thing, whether it is the
second implementation of something we already have, or whether a test passes
regardless of the feature — CLAUDE.md's Wiring & seams, which is where this
project's shipped bugs live. So the agency panel runs before code leaves the
machine, and a hook enforces it instead of a rule asking someone to remember.

WHY PYTHON, when its two sibling guards are shell. The first cut of this was
shell, matching a regex against the command string, and the audit panel put two
independently sufficient bypasses through it within minutes:

    git -C /some/repo push        # a flag that takes a value, so the
    gh --repo owner/name pr create #   subcommand is not where the regex looked
    git pu''sh origin main        # quote splicing the shell removes and a
                                  #   span-stripping sed does not

The first is not an attack — it is ordinary syntax anyone might type to avoid a
`cd`. A matcher that reads a shell command has to tokenize it the way the shell
does, and `shlex` does exactly that, so the rule is expressed over ARGV instead
of over text. Both bypasses above are covered by the test suite beside this file.

WHAT THIS IS NOT. A process gate against my own forgetfulness, not a security
boundary against a determined adversary — anything with a subshell, an alias, or
an env indirection (`$(echo pu)sh`) will get through, and that is accepted. The
threat model is a busy agent taking a shortcut, not someone attacking their own
repository.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import shlex
import subprocess
import sys

RECEIPT = ".claude/state/audit-receipt.json"

# argv[0] -> the subcommands that mutate the remote or the PR.
GATED = {
    "git": {"push"},
    "gh": {"pr"},
}
GH_PR_ACTIONS = {"create", "edit", "ready", "merge"}


def run(args: list[str], cwd: str) -> str:
    """A git command's stdout, or "" if it fails for any reason."""
    try:
        p = subprocess.run(args, cwd=cwd, capture_output=True, text=True, timeout=10)
        return p.stdout if p.returncode == 0 else ""
    except (OSError, subprocess.SubprocessError):
        return ""


def strip_heredocs(cmd: str) -> str:
    """Drop heredoc BODIES, keeping the command that introduced them.

    A heredoc body is data, not commands: `cat > notes.md <<'EOF'` followed by a
    paragraph mentioning a push must not trip the gate. This file, and the skill
    documenting it, are both written that way — the first shell version denied
    its own authorship, and so did one of its siblings before it.
    """
    # Find each `<<MARKER` / `<<-MARKER` / `<<'MARKER'` and skip to its terminator.
    lines = cmd.splitlines()
    kept: list[str] = []
    skip_until: str | None = None
    for line in lines:
        if skip_until is not None:
            if line.strip() == skip_until:
                skip_until = None
            continue
        kept.append(line)
        m = re.search(r"<<-?\s*(['\"]?)([A-Za-z_][A-Za-z0-9_]*)\1", line)
        if m:
            skip_until = m.group(2)
    return "\n".join(kept)


def segments(cmd: str) -> list[list[str]]:
    """The command split into argv lists, one per shell segment.

    Splitting on `&&`, `||`, `;`, `|` and newlines matters in both directions:
    `make && git push` must be caught, and `echo push` must not be — the word
    has to be the SUBCOMMAND of its own segment, not merely present somewhere.
    """
    lex = shlex.shlex(cmd, posix=True, punctuation_chars=True)
    lex.whitespace_split = True
    try:
        tokens = list(lex)
    except ValueError:
        # Unbalanced quotes: unparseable, so fall back to a crude substring test
        # and let the caller fail CLOSED on a hit rather than guess.
        raise

    out: list[list[str]] = [[]]
    for tok in tokens:
        if tok in ("&&", "||", ";", "|", "&", "\n"):
            out.append([])
        else:
            out[-1].append(tok)
    return [s for s in out if s]


def classify(argv: list[str]) -> str | None:
    """The gated action this segment performs, or None."""
    if not argv:
        return None
    prog = os.path.basename(argv[0])
    if prog not in GATED:
        return None
    # Scan the remaining words for the subcommand. Positional scanning is what
    # the shell version got wrong: `git -C <dir> push` puts a bare value between
    # the flag and the subcommand, so anything anchored to position 1 misses it.
    rest = argv[1:]
    if prog == "git" and "push" in rest:
        return "push (which updates the PR)"
    if prog == "gh" and "pr" in rest:
        after = rest[rest.index("pr") + 1 :]
        if any(a in GH_PR_ACTIONS for a in after):
            return "open or update this PR"
    return None


def target_dir(cmd_segments: list[list[str]], default: str) -> str:
    """Where the gated command actually operates.

    Follows a leading `cd`, and `git -C <dir>`, so a push in another checkout is
    judged against THAT repo's receipt rather than this one's — otherwise the
    gate either denies an unrelated repo for no reason or, worse, waves it
    through on a receipt that was never about it.
    """
    cwd = default
    for argv in cmd_segments:
        if argv and os.path.basename(argv[0]) == "cd" and len(argv) > 1:
            cwd = argv[1] if os.path.isabs(argv[1]) else os.path.join(cwd, argv[1])
        if argv and os.path.basename(argv[0]) == "git" and "-C" in argv:
            i = argv.index("-C")
            if i + 1 < len(argv):
                d = argv[i + 1]
                cwd = d if os.path.isabs(d) else os.path.join(cwd, d)
    return cwd


def state(repo: str) -> tuple[str, str]:
    """(HEAD, a hash of the working tree's CONTENT).

    Content, not `git status --porcelain` — that prints paths and status codes,
    so editing an already-modified file leaves its line, and the hash, identical.
    A receipt keyed to it would certify code the audit never saw, which is the
    one thing a receipt must never do.
    """
    head = run(["git", "rev-parse", "HEAD"], repo).strip()
    h = hashlib.sha256()
    h.update(run(["git", "diff", "HEAD"], repo).encode())  # staged + unstaged content
    untracked = run(["git", "ls-files", "--others", "--exclude-standard"], repo).split("\n")
    for path in sorted(f for f in untracked if f):
        h.update(path.encode())
        try:
            with open(os.path.join(repo, path), "rb") as fh:
                h.update(hashlib.sha256(fh.read()).hexdigest().encode())
        except OSError:
            h.update(b"<unreadable>")
    return head, h.hexdigest()


def deny(reason: str, action: str) -> None:
    msg = (
        f"Blocked: {reason}\n\n"
        f"Before you {action}, audit it with the agency agents — run the `audit-agency` skill. "
        "It fans six read-only reviewers over `main...HEAD` (mobile, backend, security, UX, UI, "
        "test quality) in ONE message so they run concurrently.\n\n"
        "Fix every 🔴 and 🟡 it surfaces, or get the owner to waive one explicitly, then record "
        "the receipt:\n"
        '  .claude/skills/audit-agency/record-receipt.sh findings-fixed "<one-line summary>"\n\n'
        "The receipt is keyed to HEAD AND the working tree's content, so commit your fixes BEFORE "
        "recording it — a receipt taken over a dirty tree certifies code the audit never saw.\n\n"
        "This is a separate question from the line-by-line diff review. The agency panel reads the "
        "same change as six specialists, and that is what caught the unreachable screen, the "
        "contract guard that could not fail, and the schema change that would have broken a live "
        "response.\n\n"
        "Escape hatch, for a push this genuinely does not apply to: REELMAP_SKIP_AUDIT=1."
    )
    json.dump(
        {
            "hookSpecificOutput": {
                "hookEventName": "PreToolUse",
                "permissionDecision": "deny",
                "permissionDecisionReason": msg,
            }
        },
        sys.stdout,
    )
    sys.exit(0)


def main() -> None:
    if os.environ.get("REELMAP_SKIP_AUDIT") == "1":
        return

    try:
        payload = json.load(sys.stdin)
    except (json.JSONDecodeError, ValueError):
        return
    cmd = (payload.get("tool_input") or {}).get("command") or ""
    if not cmd.strip():
        return

    body = strip_heredocs(cmd)
    try:
        segs = segments(body)
    except ValueError:
        # Unparseable quoting. Fail CLOSED if the raw text even looks like one of
        # these, rather than allowing something we could not read.
        if re.search(r"\bgit\b.*\bpush\b|\bgh\b.*\bpr\b", body, re.S):
            deny("The command could not be parsed (unbalanced quotes), so the gate cannot tell what it does.", "run this")
        return

    action = next((a for s in segs if (a := classify(s))), None)
    if action is None:
        return

    default_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
    repo = target_dir(segs, default_dir)
    if not os.path.isdir(repo):
        deny(f"The target directory ({repo}) does not exist, so no audit receipt could be checked.", action)

    receipt_path = os.path.join(repo, RECEIPT)
    if not os.path.isfile(receipt_path):
        deny("No agency audit has been recorded for this branch.", action)

    try:
        with open(receipt_path) as fh:
            receipt = json.load(fh)
    except (OSError, json.JSONDecodeError, ValueError):
        deny("The audit receipt is unreadable or corrupt, so it certifies nothing.", action)

    head, tree = state(repo)
    if not head:
        deny(f"Could not read HEAD in {repo} — not a git repository, or git failed.", action)
    if receipt.get("head") != head:
        deny(
            f"The audit receipt is for {str(receipt.get('head'))[:8]}, but HEAD is now {head[:8]} — "
            "the commit changed after it was audited.",
            action,
        )
    if receipt.get("tree") != tree:
        deny(
            "The audit receipt matches HEAD, but the working tree's content has changed since — "
            "those edits are not covered by it.",
            action,
        )


if __name__ == "__main__":
    main()
