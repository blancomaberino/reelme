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

import fnmatch
import hashlib
import json
import os
import re
import shlex
import stat
import subprocess
import sys
from typing import NoReturn

RECEIPT = ".claude/state/audit-receipt.json"
# How this file is addressed inside a repo that carries it — the scope test.
HOOK_REL = os.path.join(".claude", "hooks", os.path.basename(__file__))

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
            # expanduser because shlex does not: `cd ~/Sites/plans/reelmap` is the
            # ORDINARY way that repo is reached, and without this it became
            # `<project>/~/Sites/plans/reelmap` and denied for "directory does not
            # exist" — the original T-149 shape, still misfiring after the first fix.
            d = os.path.expanduser(argv[1])
            cwd = d if os.path.isabs(d) else os.path.join(cwd, d)
        if argv and os.path.basename(argv[0]) == "git":
            # EVERY `-C`, in order. git applies them sequentially and relative to
            # each other, so `git -C /tmp/plain -C /path/to/repo push` really does
            # operate on the second — while `argv.index("-C")` saw only the first
            # and judged the wrong directory. With the scope check that became a
            # bypass: point the first operand at a repo that does not carry this
            # gate and the push went through unaudited. Found in review; git's own
            # behaviour confirmed with `git -C a -C b rev-parse --show-toplevel`.
            for i, tok in enumerate(argv):
                if tok == "-C" and i + 1 < len(argv):
                    d = os.path.expanduser(argv[i + 1])
                    cwd = d if os.path.isabs(d) else os.path.join(cwd, d)
    return cwd


GROUNDING_MARKER = ".claude/state/grounding.json"
GROUNDING_LOG = ".claude/state/grounding.log"


def grounding_digest(path) -> str:
    """sha256 of the grounding log, or "" when it cannot be read.

    Lives here, beside `state()`, because three callers need the SAME number and
    two copies of a hash calculation drift — and drift here means a marker that
    validates when it should not. What this proves is narrow and worth stating:
    it binds the MARKER to the LOG, never the log to an execution that happened.
    A hand-written pair passes. That is acceptable under this file's threat
    model (a busy agent taking a shortcut, not an attacker); it is a staleness
    check, not a tamper control.
    """
    try:
        with open(path, "rb") as fh:
            return hashlib.sha256(fh.read()).hexdigest()
    except OSError:
        return ""


# The grounding pass's own section headers, and the rule for which tools a diff
# requires. They live HERE, in the file every side already imports, because a
# marker that names its own `required_tools` is a claim by the writer — and both
# readers used to take it. Setting `"required_tools": []` beside a log saying
# `_skipped — gitleaks not installed` satisfied every check.
SECTION_FOR = {
    "gitleaks": "Secret scan (gitleaks)",
    "semgrep": "Static analysis (semgrep)",
    "osv-scanner": "Dependency vulnerabilities",
    "actionlint": "GitHub Actions lint (actionlint)",
    "hadolint": "Dockerfile lint (hadolint)",
    "shellcheck": "Shell lint (shellcheck)",
}

# Mirrors the globs ground.sh selects files with. A subset would mean a diff the
# pass DOES scan, with the scanner missing, recorded as ok.
LOCKFILE_GLOBS = (
    "*composer.lock", "*package-lock.json", "*yarn.lock", "*pnpm-lock.yaml",
    "*Gemfile.lock", "*poetry.lock", "*go.sum", "*go.mod", "*Cargo.lock",
    "*requirements*.txt",
)


def changed_files(repo: str, base: str) -> list[str]:
    """The pass's file set: committed range plus working tree, on-disk only.

    `-z`, because git C-QUOTES a path with non-ASCII or control bytes in
    `--name-only` — and a quoted name is not the name on disk, so the `[ -e ]`
    filter dropped it and the extension tests read the escapes rather than the
    suffix. `[ -e ]` itself mirrors ground.sh, which applies it too: without it a
    file deleted in the working tree makes the two counts disagree.
    """
    out = []
    for args in (
        ["git", "diff", "-z", "--name-only", "--diff-filter=ACMR", f"{base}...HEAD"],
        ["git", "diff", "-z", "--name-only", "--diff-filter=ACMR", "HEAD"],
    ):
        out += [f for f in run(args, repo).split("\0") if f]
    return sorted({f for f in out if os.path.exists(os.path.join(repo, f))})


def required_tools(files) -> set:
    """Which scanners this diff obliges the pass to have run."""
    required = {"gitleaks", "semgrep"}
    for f in files:
        name = os.path.basename(f)
        if fnmatch.fnmatch(name, "*.sh") or fnmatch.fnmatch(name, "*.bash"):
            required.add("shellcheck")
        if f.startswith(".github/workflows/") and fnmatch.fnmatch(name, "*.y*ml"):
            required.add("actionlint")
        if fnmatch.fnmatch(name, "*Dockerfile*") or fnmatch.fnmatch(name, "*.dockerfile"):
            required.add("hadolint")
        if any(fnmatch.fnmatch(name, g) for g in LOCKFILE_GLOBS):
            required.add("osv-scanner")
    return required


def skipped_tools(log_text: str) -> set:
    """Tools the pass reported as not installed, read from the log itself."""
    return set(re.findall(r"^_skipped — (\S+) not installed", log_text, re.M))


def load_marker(repo: str, action: str) -> dict:
    """The grounding marker as a dict, or a denial.

    `isinstance` because a marker that is a JSON list or string reached
    `.get()` and surfaced as "The audit gate errored (AttributeError…)" — a safe
    deny with an unactionable sentence.
    """
    try:
        with open(os.path.join(repo, GROUNDING_MARKER)) as fh:
            marker = json.load(fh)
    except (OSError, json.JSONDecodeError, ValueError):
        deny(
            "The receipt refers to a grounding pass whose marker is missing or corrupt. "
            "Re-run .claude/skills/audit-agency/run-grounding.sh",
            action,
        )
    if not isinstance(marker, dict):
        deny(
            "The grounding marker is not an object. Re-run run-grounding.sh.",
            action,
        )
    return marker


def verify_marker_binding(marker: dict, head: str, tree: str, repo: str, action: str) -> None:
    """Bind the marker to THIS commit, tree, log and range.

    Shared by the `ok` and `skipped` branches on purpose: two copies of this
    would drift, and the branch that drifted would be the lenient one.
    """
    if marker.get("head") != head or marker.get("tree") != tree:
        deny(
            "The grounding marker is for a different commit or tree than the receipt. "
            "Re-run run-grounding.sh, then re-record the receipt.",
            action,
        )
    if marker.get("digest") != grounding_digest(os.path.join(repo, GROUNDING_LOG)):
        deny(
            "The grounding log does not match the digest its marker recorded — the log was "
            "replaced or removed after the pass. Re-run run-grounding.sh.",
            action,
        )
    # The BASE too. A `git fetch` moves origin/main while HEAD and the tree stand
    # still, so the receipt and the digest still validate over a pass that
    # covered a different range. Same ref preference as the two sibling scripts.
    expected_base = ""
    for ref in ("origin/main", "main"):
        expected_base = run(["git", "merge-base", ref, "HEAD"], repo).strip()
        if expected_base:
            break
    # When NEITHER ref resolves there is no base to compare against, and this
    # check does not apply — deliberately lenient where check-grounding.py is
    # strict, because that one runs at record time (where refusing is
    # recoverable) and this one runs at push time (where it is not). A receipt
    # cannot exist without passing the strict one first.
    if expected_base and marker.get("base") != expected_base:
        deny(
            "The grounding pass covered a different range than this branch has now — the base "
            "moved (a fetch, a rebase). Re-run run-grounding.sh and re-record.",
            action,
        )


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


def warn(reason: str) -> None:
    """Say it loudly and let the push through.

    For the grounding hatch. Refusing there would leave `REELMAP_SKIP_AUDIT=1`
    as the only exit, which skips this whole gate and leaves no artifact —
    trading a RECORDED skip for an invisible one.

    `systemMessage` on STDOUT, the same channel the not-the-protected-repo skip
    already uses. A first version printed to stderr, which is surfaced only on a
    non-zero exit — so "allow loudly" was allow SILENTLY, and `"grounding":
    "skipped"` became the cheapest path through the entire gate: no marker, no
    log, no digest, and nothing visible at push time. A control that is
    routinely bypassed is decoration; one whose bypass is invisible is worse.
    """
    json.dump({"systemMessage": f"⚠️  {reason}"}, sys.stdout)
    sys.exit(0)


def deny(reason: str, action: str) -> NoReturn:
    msg = (
        f"Blocked: {reason}\n\n"
        f"Before you {action}, audit it with the agency agents — run the `audit-agency` skill. "
        "It fans read-only reviewers over `main...HEAD` in ONE message so they run concurrently.\n\n"
        "Which seats: run `.claude/skills/audit-agency/select-lanes.sh` — it decides from the files "
        "the diff touches (CLAUDE.md §4). Security (`Senior SecOps Engineer`) and Architecture "
        "(`Software Architect`, NOT `Backend Architect`) sit on every non-docs diff; a docs-only diff "
        "records `record-receipt.sh docs-only` instead.\n\n"
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

    # The SHELL's cwd first. A session already sitting in another checkout runs
    # `git push` with no `cd` in the command, and judging that against the
    # project directory blamed the wrong repository — it reported a stale receipt
    # for a repo the push was not touching, which is worse than a bare refusal
    # because it is specific and plausible and sends you fixing the wrong thing.
    # (Observed while pushing the plan repo; T-149.)
    fallback = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()
    payload_cwd = payload.get("cwd")
    default_dir = payload_cwd if isinstance(payload_cwd, str) and payload_cwd else fallback
    # A relative `cwd` would otherwise resolve against the hook process's own
    # working directory, which nothing defines. Anchor it to the project.
    if not os.path.isabs(default_dir):
        default_dir = os.path.join(fallback, default_dir)
    where = target_dir(segs, default_dir, gated_at)
    if not os.path.isdir(where):
        deny(f"The target directory ({where}) does not exist, so no audit receipt could be checked.", action)

    protected = os.environ.get("CLAUDE_PROJECT_DIR") or os.path.dirname(
        os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    )

    # SCOPE: does the target repository CARRY THIS GATE?
    #
    # This gate protects the project it ships with. Denying pushes in unrelated
    # checkouts taught the only lesson a misfiring guard can teach — reach for
    # the escape hatch — and the plan repo (task specs and a JSON queue, its own
    # convention, no PRs) was refused for never having recorded a receipt, which
    # it never should.
    #
    # Two weaker predicates were tried and both were bypasses, each found in
    # review and reproduced against this file:
    #   - TOPLEVEL comparison: a linked worktree reports its own toplevel, so
    #     `git worktree add /tmp/wt` and a push from there sailed through
    #     unaudited — same remote, same branch, same PR. Not exotic; this
    #     project's own tooling offers `isolation: "worktree"`.
    #   - GIT COMMON DIR: fixes worktrees, still misses a second CLONE.
    #
    # Presence of the hook survives all of them. A worktree has it checked out; a
    # clone has it; the plan repo never did. It is also self-describing: a repo is
    # in scope exactly when it carries the thing doing the gating, so there is no
    # separate notion of identity to keep true.
    #
    # The remote URL is deliberately NOT the discriminator — the plan repo shares
    # this repo's `origin`, so matching on it would put the very repository this
    # scoping exists for straight back in scope.
    toplevel = run(["git", "rev-parse", "--show-toplevel"], where).strip()
    if toplevel and not os.path.isfile(os.path.join(toplevel, HOOK_REL)):
        # Announced, not silent. A guard that allows without a word is the one
        # nobody debugs, and both bypasses above would have been visible the day
        # they were introduced if this line had existed.
        json.dump(
            {"systemMessage": f"audit gate: {toplevel} does not carry this gate — not the protected repo, skipping."},
            sys.stdout,
        )
        return

    # The receipt lives at the TOPLEVEL. Using the raw cwd meant a push from
    # `apps/api` looked for `apps/api/.claude/state/…`, found nothing, and
    # reported "no agency audit has been recorded" about a branch that had just
    # been audited — the same confidently-wrong shape this change set out to
    # remove, reintroduced one line lower.
    repo = toplevel or where

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

    # The grounding half of the review (T-156). `record-receipt.sh` already
    # refuses to WRITE a receipt without a valid marker, so reading the key
    # alone would only catch a receipt that script did not write — and a
    # hand-written one sets "ok" as easily as it omits it. So re-verify the
    # artifact here, from the values just computed.
    grounding = receipt.get("grounding")
    if grounding is None:
        deny(
            "The audit receipt predates the grounding check, or was not written by "
            "record-receipt.sh. Re-record it: .claude/skills/audit-agency/record-receipt.sh",
            action,
        )
    elif grounding == "skipped":
        # A skip still leaves a marker — run-grounding.sh writes one on that path
        # too — so it is verified like any other. Before this, `skipped` needed
        # nothing but the word: no marker, no log, no digest. That made the
        # hatch's artifact cheaper to obtain than a real pass AND able to
        # certify one, which is the false green this rebuild exists to close.
        marker = load_marker(repo, action)
        if marker.get("skipped") is not True:
            deny(
                "The receipt records a skipped grounding pass, but its marker does not. "
                "Re-run run-grounding.sh and re-record the receipt.",
                action,
            )
        verify_marker_binding(marker, head, tree, repo, action)
        # Allowed, and loud. Refusing would leave REELMAP_SKIP_AUDIT=1 as the
        # only exit — which skips this whole gate and leaves no artifact at all,
        # trading a recorded skip for an invisible one.
        warn(
            "The grounding pass was SKIPPED for this tree, so the scripted half of the review "
            "(gitleaks, semgrep, osv-scanner, actionlint, hadolint, shellcheck) did not run. "
            "The PR body must say so."
        )
    elif grounding == "ok":
        marker = load_marker(repo, action)
        verify_marker_binding(marker, head, tree, repo, action)
        # Every property check-grounding.py refuses on. Checking fewer here
        # than there would mean a marker record-receipt.sh would have rejected
        # still walks past the push guard — and this block exists precisely
        # because a receipt can claim more than its marker supports.
        if marker.get("skipped") is not False:
            deny(
                "The receipt says the grounding pass ran, but its marker records a skip. "
                "Re-run run-grounding.sh and re-record the receipt.",
                action,
            )
        # DERIVED from the diff and the log, not read from the marker. Both
        # operands used to come out of the file the writer wrote, so they could
        # never disagree with it: `"required_tools": []` beside a log saying
        # gitleaks was not installed passed both readers.
        try:
            log_text = open(os.path.join(repo, GROUNDING_LOG), errors="replace").read()
        except OSError:
            log_text = ""
        missing = required_tools(changed_files(repo, marker.get("base") or "")) & skipped_tools(log_text)
        if missing:
            deny(
                "The grounding pass skipped " + ", ".join(sorted(missing)) + ", which this diff "
                "requires. Install and re-run run-grounding.sh — a pass that skipped what the "
                "diff called for certifies nothing.",
                action,
            )
    else:
        deny(
            f"The audit receipt records an unrecognised grounding state {str(grounding)[:40]!r}. "
            "Re-record the receipt.",
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
