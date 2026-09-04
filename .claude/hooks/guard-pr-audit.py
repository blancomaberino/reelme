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
import stat
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
        # errors="replace": a tracked file holding invalid UTF-8 (a binary blob,
        # a latin-1 fixture) made `git diff` undecodable, and the resulting
        # UnicodeDecodeError escaped this function, killed the hook, and printed
        # NOTHING — which the harness reads as allow. A gate that fails open on
        # a byte is not a gate.
        p = subprocess.run(
            args, cwd=cwd, capture_output=True, text=True, errors="replace", timeout=10
        )
        return p.stdout if p.returncode == 0 else ""
    except (OSError, subprocess.SubprocessError, UnicodeDecodeError, ValueError):
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
    # Lines are split BEFORE tokenizing, not by shlex. A newline separates two
    # commands exactly like `;`, but shlex's default whitespace set swallows it,
    # so `cd /tmp\ngit push` tokenized to the single argv ['cd','/tmp','git',
    # 'push'] — argv[0] is `cd`, the push was never classified, and the gate let
    # it through. Multi-line commands are the normal way these get written, so
    # that was not an edge case, it was most of them. Removing `\n` from
    # lex.whitespace is NOT the fix: it makes the newline part of the adjacent
    # token ('/tmp\ngit') instead of a separator.
    # A backslash-newline is a CONTINUATION, not a separator — the shell joins
    # those lines into one command, and splitting on the newline first would
    # both mis-segment it and leave a trailing backslash that shlex refuses.
    # `git \<newline> push origin main` is an ordinary way to write a long
    # invocation, and it reached the unparseable fallback instead of being read.
    cmd = cmd.replace("\\\n", " ")

    out: list[list[str]] = [[]]
    for line in cmd.split("\n"):
        if not line.strip():
            continue
        lex = shlex.shlex(line, posix=True, punctuation_chars=True)
        lex.whitespace_split = True
        # Unbalanced quotes raise ValueError; re-raised so the caller fails
        # CLOSED on a hit rather than guessing.
        for tok in lex:
            if tok in ("&&", "||", ";", "|", "&"):
                out.append([])
            else:
                out[-1].append(tok)
        out.append([])  # end of line = end of command
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


def target_dir(cmd_segments: list[list[str]], default: str, upto: int) -> str:
    """Where the gated command at index `upto` actually operates.

    Follows a preceding `cd`, and that segment's own `git -C <dir>`, so a push in
    another checkout is judged against THAT repo's receipt rather than this
    one's — otherwise the gate either denies an unrelated repo for no reason or,
    worse, waves it through on a receipt that was never about it.

    `upto` is the point of the whole signature. Scanning EVERY segment meant a
    trailing `cd` changed where the receipt was looked for: `git push origin main
    ; cd ../other-repo` pushed here and was judged against ../other-repo. That is
    an ordinary shape — a follow-up `cd` for the next command — not an attack.
    A directory change after the push cannot affect the push.
    """
    cwd = default
    for argv in cmd_segments[: upto + 1]:
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
        # The receipt never describes itself. In this repo `.claude/state/` is
        # gitignored so it never appeared here anyway, but in a checkout where it
        # is not, writing the receipt changed the very hash it had just recorded
        # and the receipt could never validate — found by the gate's own
        # hermetic test, which built a scratch repo with no such ignore rule.
        if path == RECEIPT:
            continue
        h.update(path.encode())
        try:
            # Regular files only. A FIFO left in the tree blocks open() forever
            # waiting for a writer, and a symlink to /dev/zero reads forever —
            # either one wedges EVERY push from then on, with no timeout to
            # recover, because this loop has none of the git calls' protection.
            if not stat.S_ISREG(os.lstat(os.path.join(repo, path)).st_mode):
                h.update(b"<non-regular>")
                continue
            with open(os.path.join(repo, path), "rb") as fh:
                h.update(hashlib.sha256(fh.read()).hexdigest().encode())
        except OSError:
            h.update(b"<unreadable>")
    return head, h.hexdigest()


def deny(reason: str, action: str) -> None:
    msg = (
        f"Blocked: {reason}\n\n"
        f"Before you {action}, audit it with the agency agents — run the `audit-agency` skill. "
        "It fans read-only reviewers over `main...HEAD` in ONE message so they run concurrently.\n\n"
        "TWO SEATS ARE MANDATORY on every diff (CLAUDE.md golden rule #3):\n"
        "  - Security      — `Senior SecOps Engineer`\n"
        "  - Architecture  — `Software Architect` (NOT `Backend Architect`; that is a different, "
        "narrower reading — correctness and N+1, not boundaries and blast radius)\n"
        "Fit the OTHER lanes to the diff: mobile, UX, UI, test quality.\n\n"
        "The receipt cannot tell which seats you filled — it hashes HEAD and the tree, nothing "
        "else. Skipping one is therefore a decision only you will ever know you made.\n\n"
        "Fix every 🔴 and 🟡 it surfaces, or get the owner to waive one explicitly, then record "
        "the receipt:\n"
        '  .claude/skills/audit-agency/record-receipt.sh findings-fixed "<one-line summary>"\n\n'
        "The receipt is keyed to HEAD AND the working tree's content, so commit your fixes BEFORE "
        "recording it — a receipt taken over a dirty tree certifies code the audit never saw.\n\n"
        "This is a separate question from the line-by-line diff review. The agency panel reads the "
        "same change as independent specialists, and that is what caught the unreachable screen, "
        "the contract guard that could not fail, the schema change that would have broken a live "
        "response, and a feature whose only write path silently discarded it.\n\n"
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
    # A non-dict top-level payload would AttributeError here and kill the hook
    # with empty stdout, which the harness reads as allow.
    if not isinstance(payload, dict):
        return
    tool_input = payload.get("tool_input")
    cmd = (tool_input.get("command") if isinstance(tool_input, dict) else "") or ""
    if not cmd.strip():
        return

    body = strip_heredocs(cmd)
    try:
        segs = segments(body)
    except ValueError:
        # Unparseable quoting. Fail CLOSED if the raw text even looks like one of
        # these, rather than allowing something we could not read.
        # Only the GATED verbs. The first cut matched any `gh … pr …`, which
        # denied `gh pr comment` — a read/write on the conversation, not on the
        # code — whenever the body contained shell-escaped quotes it could not
        # parse. Failing closed is right; failing closed on commands that were
        # never gated is just a broken tool.
        # `.*` with DOTALL, not `[^\n]*`. Bounding this to a line was a mistake
        # copied from `segments()`, where the bound is load-bearing: here the
        # text is ALREADY unparseable, so there is no separator to protect, and
        # the bound only created a hole — a gated verb one line below its
        # program (a continuation, a wrapped invocation) stopped matching and
        # the command was allowed. Last-resort matching should over-match.
        if re.search(
            r"\bgit\b.*\bpush\b|\bgh\b.*\bpr\b.*\b(create|edit|ready|merge)\b",
            body,
            re.S,
        ):
            deny("The command could not be parsed (unbalanced quotes), so the gate cannot tell what it does.", "run this")
        return

    action, gated_at = None, 0
    for i, seg in enumerate(segs):
        if (a := classify(seg)) is not None:
            action, gated_at = a, i
            break
    if action is None:
        return

    default_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
    repo = target_dir(segs, default_dir, gated_at)
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
    # Fail CLOSED on anything unforeseen. Four separate point-fixes this session
    # (newline splitting, the target_dir index, the receipt hashing itself, a
    # non-regular untracked file) were all the SAME failure: an unhandled case
    # produced empty stdout, and empty stdout means allow. Patching each one as
    # it was found leaves the next unanticipated case silently open, so the
    # default itself is inverted — an unexpected error is now a loud deny.
    # SystemExit is how deny() and the allow paths return, so it must pass through.
    try:
        main()
    except SystemExit:
        raise
    except BaseException as exc:  # noqa: BLE001 — deliberately total
        deny(
            f"The audit gate errored ({type(exc).__name__}: {exc}), so it cannot vouch for this commit.",
            "run this",
        )
