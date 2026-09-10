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


# A receipt key written as an explicit JSON null, distinct from "omit this key".
NULL = object()


def decision(cmd, env_extra=None, project_dir=ROOT, cwd=None):
    """The ALLOW/DENY alone — most cases only care about that."""
    return judge(cmd, env_extra, project_dir, cwd)[0]


def judge(cmd, env_extra=None, project_dir=ROOT, cwd=None):
    """(decision, the denial's own words).

    A case that asserts only DENY stays green when an unrelated required key
    starts denying first and the check it was written for rots — so anything
    guarding one specific key pins the reason too.
    """
    env = dict(os.environ, CLAUDE_PROJECT_DIR=project_dir)
    env.pop("REELMAP_SKIP_AUDIT", None)
    env.update(env_extra or {})
    payload = {"tool_input": {"command": cmd}}
    if cwd is not None:
        payload["cwd"] = cwd
    p = subprocess.run(
        [sys.executable, HOOK],
        input=json.dumps(payload),
        capture_output=True,
        text=True,
        env=env,
    )
    if p.returncode != 0:
        return (f"CRASH: {p.stderr.strip()[:200]}", "")
    # Read the DECISION, not the presence of output. The gate also prints a
    # `systemMessage` when it skips a repo that does not carry it — announced on
    # purpose, since a guard that allows silently is the one nobody debugs — and
    # treating any stdout as a denial scored those skips as denials.
    out = p.stdout.strip()
    if not out:
        return ("ALLOW", "")
    try:
        decided = json.loads(out)
    except (json.JSONDecodeError, ValueError):
        return (f"MALFORMED: {out[:120]}", "")
    hook_out = decided.get("hookSpecificOutput") or {}
    verdict = "DENY" if hook_out.get("permissionDecision") == "deny" else "ALLOW"
    return (verdict, hook_out.get("permissionDecisionReason") or "")


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
        subprocess.run(["git", "init", "-q", "-b", "main", clean], check=True)
        subprocess.run(["git", "-C", clean, "config", "user.email", "t@t"], check=True)
        subprocess.run(["git", "-C", clean, "config", "user.name", "t"], check=True)
        (pathlib.Path(clean) / "f.txt").write_text("hello\n")
        # Both fixtures must CARRY THE GATE, or the scope check allows before it
        # reads any receipt — so the control passed without ever validating one,
        # and the laundering assertion never reached receipt lookup. The state
        # dir must be ignored, or the artifacts invalidate the tree they record.
        (pathlib.Path(clean) / ".gitignore").write_text(".claude/state/\n")
        for r in (clean, bare):
            dst = pathlib.Path(r) / ".claude" / "hooks" / pathlib.Path(HOOK).name
            dst.parent.mkdir(parents=True, exist_ok=True)
            dst.write_text(pathlib.Path(HOOK).read_text())
        subprocess.run(["git", "-C", clean, "add", "-A"], check=True)
        subprocess.run(["git", "-C", clean, "commit", "-qm", "init"], check=True)

        spec = importlib.util.spec_from_file_location("guard", HOOK)
        guard = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(guard)
        head, tree = guard.state(clean)
        rec = pathlib.Path(clean) / ".claude" / "state"
        rec.mkdir(parents=True)
        # A skip is verified like any other state, so the control needs the
        # marker too — otherwise it allows for the wrong reason again.
        (rec / "grounding.log").write_text("skipped\n")
        (rec / "grounding.json").write_text(json.dumps({
            "head": head, "tree": tree,
            "base": guard.run(["git", "merge-base", "main", "HEAD"], clean).strip(),
            "skipped": True, "leads": 0,
            "digest": guard.grounding_digest(rec / "grounding.log"),
        }))
        # `grounding: skipped` because this fixture is about receipt LAUNDERING,
        # not about the grounding pass — skipped is allowed-and-loud, so the
        # control still allows and the case keeps testing what it tested.
        (rec / "audit-receipt.json").write_text(
            json.dumps({"head": head, "tree": tree, "verdict": "clean",
                        "grounding": "skipped", "declines": "none"})
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

    # [T-156] The grounding half of the review. The third reproduced false green
    # was that the push guard never read this field at all, so a receipt could
    # say anything — or nothing — about whether the scripted pass ran.
    with tempfile.TemporaryDirectory() as repo:
        # `-b main`, or no merge base resolves and the base check silently does
        # not apply — which is how the case for it passed as ALLOW.
        subprocess.run(["git", "init", "-q", "-b", "main", repo], check=True)
        subprocess.run(["git", "-C", repo, "config", "user.email", "t@t"], check=True)
        subprocess.run(["git", "-C", repo, "config", "user.name", "t"], check=True)
        (pathlib.Path(repo) / "f.txt").write_text("hello\n")
        # The tree hash counts UNTRACKED files, so without this the receipt and
        # the marker invalidate the very tree they record.
        (pathlib.Path(repo) / ".gitignore").write_text(".claude/state/\n")
        # The gate SKIPS any repo that does not carry it — so without this the
        # temp repo allows everything and every case below passes for the wrong
        # reason. (That is why the laundering control above allows, too.)
        hook_dst = pathlib.Path(repo) / ".claude" / "hooks" / pathlib.Path(HOOK).name
        hook_dst.parent.mkdir(parents=True, exist_ok=True)
        hook_dst.write_text(pathlib.Path(HOOK).read_text())
        subprocess.run(["git", "-C", repo, "add", "-A"], check=True)
        subprocess.run(["git", "-C", repo, "commit", "-qm", "init"], check=True)

        spec = importlib.util.spec_from_file_location("guard", HOOK)
        guard = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(guard)
        head, tree = guard.state(repo)
        state_dir = pathlib.Path(repo) / ".claude" / "state"
        state_dir.mkdir(parents=True)

        def write_receipt(**extra):
            # `declines` is in the default because the current record-receipt.sh
            # always writes it; a case that wants it ABSENT passes declines=None.
            # NULL is how a case asks for an explicit JSON `null` — a shape a
            # hand-writer produces and `None` here cannot express, since None is
            # the signal to drop the key entirely.
            body = {"head": head, "tree": tree, "verdict": "clean", "declines": "none"}
            body.update(extra)
            body = {k: (None if v is NULL else v)
                    for k, v in body.items() if v is not None}
            (state_dir / "audit-receipt.json").write_text(json.dumps(body))

        def write_marker(log_text="grounded\n", **extra):
            (state_dir / "grounding.log").write_text(log_text)
            body = {
                "head": head,
                "tree": tree,
                "base": guard.run(["git", "merge-base", "main", "HEAD"], repo).strip(),
                "skipped": False,
                "digest": guard.grounding_digest(state_dir / "grounding.log"),
            }
            body.update(extra)
            (state_dir / "grounding.json").write_text(json.dumps(body))

        grounding_cases = []

        # A receipt from before this check existed says nothing about grounding.
        write_receipt()
        grounding_cases.append(("receipt without the key", decision(PUSH, project_dir=repo), "DENY"))

        # The declines key (CLAUDE.md §4). The writer refusing to emit a bad
        # receipt only ever catches receipts that writer wrote, so the hook
        # re-checks — and these pin the REASON, because every one of them denies
        # for a reason the case beside it would also satisfy.
        #
        # Presence was the whole check once, and it was two characters from
        # useless: `""`, null and false all passed, which is the same "escape that
        # costs one word" record-receipt.sh cites for not exempting `clean`. The
        # writer refuses an empty value at PARSE time, so accepting one here let
        # writer and reader disagree about what an empty disposition means.
        def declines_case(name, want, want_reason, **extra):
            write_receipt(grounding="ok", **extra)
            write_marker()
            got, why = judge(PUSH, project_dir=repo)
            good = got == want and want_reason in why
            print(f"{'PASS' if good else 'FAIL'}  {'[T-156] ' + name:44s} "
                  f"want={want:5s} got={got}")
            if not good:
                if got != want:
                    failures.append(f"declines: {name} (want {want}, got {got})")
                else:
                    failures.append(f"declines: {name} denied for the wrong reason: {why[:120]!r}")

        declines_case("no declines key at all", "DENY",
                      "has no `declines`", declines=None)
        # Each of these is a DIFFERENT refusal from the one above — the key is
        # there, so only the emptiness check can catch them.
        declines_case("an empty declines", "DENY",
                      "`declines` is empty", declines="")
        declines_case("whitespace is not a disposition", "DENY",
                      "`declines` is empty", declines="   ")
        declines_case("null declines", "DENY",
                      "`declines` is empty", declines=NULL)
        declines_case("a non-string declines", "DENY",
                      "`declines` is empty", declines=False)
        # ...and the exemption must survive the tightening: `docs-only` is exempt
        # from the flag, so record-receipt.sh writes the key EMPTY on purpose. A
        # bare falsiness test here would have denied every docs-only receipt.
        declines_case("docs-only may leave it empty", "ALLOW", "",
                      verdict="docs-only", declines="")

        # The honest states.
        write_receipt(grounding="ok")
        write_marker()
        grounding_cases.append(("ok + a marker that verifies", decision(PUSH, project_dir=repo), "ALLOW"))

        # A skip is verified like any other state — it leaves a marker too.
        write_receipt(grounding="skipped")
        write_marker(skipped=True)
        grounding_cases.append(("skipped is allowed", decision(PUSH, project_dir=repo), "ALLOW"))

        # ...but only with its own marker. Before this, `skipped` needed nothing
        # but the word, which made the hatch's artifact cheaper than a real pass.
        (state_dir / "grounding.json").unlink()
        grounding_cases.append(("skipped with NO marker", decision(PUSH, project_dir=repo), "DENY"))

        # And a marker that records a real pass must not certify a claimed skip,
        # nor the reverse — the two states are not interchangeable.
        write_marker(skipped=False)
        grounding_cases.append(("skipped over a ran marker", decision(PUSH, project_dir=repo), "DENY"))
        write_receipt(grounding="ok")
        write_marker(skipped=True)
        grounding_cases.append(("ok over a skipped marker", decision(PUSH, project_dir=repo), "DENY"))

        # Restore the state the visibility check below expects.
        write_receipt(grounding="skipped")
        write_marker(skipped=True)

        # ALLOW is half the claim. The word is "loudly", and the first version
        # printed to stderr — which is surfaced only on a NON-ZERO exit, so the
        # message went nowhere and the skip was the cheapest, quietest path
        # through the whole gate. Asserting the decision alone passed either way.
        env = dict(os.environ, CLAUDE_PROJECT_DIR=repo)
        env.pop("REELMAP_SKIP_AUDIT", None)
        spoken = subprocess.run(
            [sys.executable, HOOK], input=json.dumps({"tool_input": {"command": PUSH}}),
            capture_output=True, text=True, env=env,
        ).stdout
        heard = "SKIPPED" in spoken and "did not run" in spoken
        print(f"{'PASS' if heard else 'FAIL'}  {'[T-156] the skip is SAID, not just allowed':44s} "
              f"want=visible got={'visible' if heard else 'silent: ' + spoken[:60]!r}")
        if not heard:
            failures.append("grounding: the skip is said")

        # Claiming `ok` is not the same as having run: the guard re-verifies the
        # artifact, because record-receipt.sh refusing to WRITE a bad receipt
        # only ever catches a receipt that record-receipt.sh wrote.
        write_receipt(grounding="ok")
        (state_dir / "grounding.json").unlink()
        grounding_cases.append(("ok with NO marker", decision(PUSH, project_dir=repo), "DENY"))

        # head AND tree, not either: no case built a marker with the right
        # commit and the wrong content, so dropping the tree half of that
        # comparison left the suite green.
        write_marker(tree="0" * 64)
        grounding_cases.append(("right head, wrong tree", decision(PUSH, project_dir=repo), "DENY"))
        write_marker(head="0" * 40)
        grounding_cases.append(("right tree, wrong head", decision(PUSH, project_dir=repo), "DENY"))
        write_marker(base="0" * 40)
        grounding_cases.append(("a base nobody would compute", decision(PUSH, project_dir=repo), "DENY"))

        write_marker()
        (state_dir / "grounding.log").write_text("replaced after the run\n")
        grounding_cases.append(("ok with a log that no longer matches", decision(PUSH, project_dir=repo), "DENY"))

        write_marker()
        write_receipt(grounding="totally fine, trust me")
        grounding_cases.append(("an unrecognised state", decision(PUSH, project_dir=repo), "DENY"))

        for name, got, want in grounding_cases:
            good = got == want
            print(f"{'PASS' if good else 'FAIL'}  {'[T-156] ' + name:44s} want={want:5s} got={got}")
            if not good:
                failures.append(f"grounding: {name}")

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

    # [audit5] SCOPE — a push in ANOTHER repository is not this gate's business.
    #
    # Observed: pushing the plan repo (task specs and a JSON queue, its own
    # convention, no PRs) was denied for never having recorded a receipt, which
    # it never should. A guard that fires where it was not meant to teaches one
    # lesson only — reach for the escape hatch — and that is how it becomes
    # decoration. Built hermetically: a real temp repo with a commit and NO
    # receipt, which is exactly the shape that used to be refused.
    with tempfile.TemporaryDirectory() as other:
        subprocess.run(["git", "init", "-q", other], check=True)
        subprocess.run(["git", "-C", other, "config", "user.email", "t@t"], check=True)
        subprocess.run(["git", "-C", other, "config", "user.name", "t"], check=True)
        (pathlib.Path(other) / "f.txt").write_text("hi\n")
        subprocess.run(["git", "-C", other, "add", "-A"], check=True)
        subprocess.run(["git", "-C", other, "commit", "-qm", "x"], check=True)

        got = decision(f"git -C {other} push", cwd=ROOT)
        ok = got == "ALLOW"
        print(f"{'PASS' if ok else 'FAIL'}  {'[audit5] another repo is not gated':38s} want=ALLOW got={got}")
        if not ok:
            failures.append("scope: other repo")

        # ...and the SHELL's cwd is what decides, with no `cd` in the command.
        # This is the case that misfired: the session sat in the other repo, the
        # command named no directory, and the hook judged it against THIS one —
        # reporting a stale receipt for a repo the push was not touching.
        got = decision("git push", cwd=other)
        ok = got == "ALLOW"
        print(f"{'PASS' if ok else 'FAIL'}  {'[audit5] payload cwd sets the target':38s} want=ALLOW got={got}")
        if not ok:
            failures.append("scope: payload cwd")

    # [audit6] A LINKED WORKTREE of this repo is still this repo.
    #
    # The first cut of the scope check compared git TOPLEVELS, and a worktree
    # reports its own — so `git worktree add /tmp/wt` then a push from there went
    # through unaudited: same remote, same branch, same PR. Found in review and
    # reproduced against the real hook. Not exotic: this project's own tooling
    # offers `isolation: "worktree"`. Identity is the git COMMON dir, which a
    # worktree shares with the main checkout.
    with tempfile.TemporaryDirectory() as wtparent:
        wt = os.path.join(wtparent, "wt")
        add_wt = subprocess.run(
            ["git", "-C", ROOT, "worktree", "add", "-q", "--detach", wt, "HEAD"],
            capture_output=True, text=True,
        )
        if add_wt.returncode != 0:
            print(f"SKIP  {'[audit6] worktree is still gated':38s} (worktree add failed)")
        else:
            try:
                got = decision("git push", cwd=wt)
                ok = got == "DENY"
                print(f"{'PASS' if ok else 'FAIL'}  {'[audit6] worktree is still gated':38s} want=DENY  got={got}")
                if not ok:
                    failures.append("scope: worktree bypass")

                got = decision(f"git -C {wt} push", cwd=ROOT)
                ok = got == "DENY"
                print(f"{'PASS' if ok else 'FAIL'}  {'[audit6] -C into a worktree gated':38s} want=DENY  got={got}")
                if not ok:
                    failures.append("scope: worktree via -C")
            finally:
                subprocess.run(["git", "-C", ROOT, "worktree", "remove", "--force", wt],
                               capture_output=True)
                subprocess.run(["git", "-C", ROOT, "worktree", "prune"], capture_output=True)

    # [audit6] The receipt lives at the TOPLEVEL. A push from a subdirectory must
    # find it — using the raw cwd reported "no agency audit has been recorded"
    # about a branch that had just been audited. Asserted on a scratch repo
    # carrying a REAL matching receipt, because this tree is dirty during
    # development and would answer DENY either way.
    with tempfile.TemporaryDirectory() as sub:
        # `-b main`, or no merge base resolves and verify_marker_binding skips
        # the base comparison — so this case would pass without ever checking
        # the binding it now depends on.
        subprocess.run(["git", "init", "-q", "-b", "main", sub], check=True)
        subprocess.run(["git", "-C", sub, "config", "user.email", "t@t"], check=True)
        subprocess.run(["git", "-C", sub, "config", "user.name", "t"], check=True)
        nested = pathlib.Path(sub) / "apps" / "api"
        nested.mkdir(parents=True)
        (nested / "f.txt").write_text("hi\n")
        # The tree hash counts untracked files, so the receipt and marker written
        # below would otherwise invalidate the very tree they record.
        (pathlib.Path(sub) / ".gitignore").write_text(".claude/state/\n")
        subprocess.run(["git", "-C", sub, "add", "-A"], check=True)
        subprocess.run(["git", "-C", sub, "commit", "-qm", "x"], check=True)

        spec = importlib.util.spec_from_file_location("guard", HOOK)
        guard = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(guard)

        # The fixture must CARRY THE GATE, or the scope check skips it and this
        # case returns ALLOW without ever reaching the receipt lookup it exists to
        # test — which is exactly what it did until review caught it. Copied
        # before the tree is hashed, so the receipt covers the file.
        gate = pathlib.Path(sub) / guard.HOOK_REL
        gate.parent.mkdir(parents=True, exist_ok=True)
        gate.write_bytes(pathlib.Path(HOOK).read_bytes())
        subprocess.run(["git", "-C", sub, "add", "-A"], check=True)
        subprocess.run(["git", "-C", sub, "commit", "-qm", "gate"], check=True)

        head = subprocess.run(["git", "-C", sub, "rev-parse", "HEAD"],
                              capture_output=True, text=True).stdout.strip()
        _, tree = guard.state(sub)
        receipt = pathlib.Path(sub) / guard.RECEIPT
        receipt.parent.mkdir(parents=True, exist_ok=True)
        # `grounding: skipped` — allowed and loud. This case is about finding the
        # receipt from a subdirectory, not about the grounding pass.
        receipt.write_text(
            json.dumps({"head": head, "tree": tree, "verdict": "clean",
                        "grounding": "skipped", "declines": "none"})
        )
        # A skip is verified like any other state, so this fixture needs the
        # marker too — it is about finding the receipt from a subdirectory.
        (receipt.parent / "grounding.log").write_text("skipped\n")
        (receipt.parent / "grounding.json").write_text(
            json.dumps({
                "head": head,
                "tree": tree,
                "base": guard.run(["git", "merge-base", "main", "HEAD"], sub).strip(),
                "skipped": True,
                "digest": guard.grounding_digest(receipt.parent / "grounding.log"),
            })
        )

        got = decision("git push", cwd=str(nested), project_dir=sub)
        ok = got == "ALLOW"
        print(f"{'PASS' if ok else 'FAIL'}  {'[audit6] subdir finds the receipt':38s} want=ALLOW got={got}")
        if not ok:
            failures.append("receipt lookup from a subdirectory")

    # [audit6] A second CLONE of this project is still this project.
    #
    # The git-common-dir predicate — the fix for the worktree bypass — still let
    # this through, which is why scope is decided by whether the target CARRIES
    # THE GATE. A clone has the hook checked out; the plan repo never did.
    with tempfile.TemporaryDirectory() as clonedir:
        clone = os.path.join(clonedir, "copy")
        cloned = subprocess.run(["git", "clone", "-q", "--no-hardlinks", "--local", ROOT, clone],
                                capture_output=True, text=True)
        if cloned.returncode != 0:
            print(f"SKIP  {'[audit6] a clone is still gated':38s} (clone failed)")
        else:
            got = decision("git push", cwd=clone)
            ok = got == "DENY"
            print(f"{'PASS' if ok else 'FAIL'}  {'[audit6] a clone is still gated':38s} want=DENY  got={got}")
            if not ok:
                failures.append("scope: clone bypass")

    # [audit6] `cd ~/elsewhere && git push` — the ORIGINAL T-149 shape.
    #
    # shlex does not expand `~`, so this became `<project>/~/elsewhere` and was
    # denied for "the target directory does not exist": a confident, wrong reason
    # about a path nobody named. Still misfiring after the first fix, which only
    # addressed the no-`cd` shape.
    with tempfile.TemporaryDirectory() as plain:
        subprocess.run(["git", "init", "-q", plain], check=True)
        subprocess.run(["git", "-C", plain, "config", "user.email", "t@t"], check=True)
        subprocess.run(["git", "-C", plain, "config", "user.name", "t"], check=True)
        (pathlib.Path(plain) / "f.txt").write_text("hi\n")
        subprocess.run(["git", "-C", plain, "add", "-A"], check=True)
        subprocess.run(["git", "-C", plain, "commit", "-qm", "x"], check=True)

        home = os.path.expanduser("~")
        if plain.startswith(home):
            tilde = "~" + plain[len(home):]
            got = decision(f"cd {tilde} && git push", cwd=ROOT)
            ok = got == "ALLOW"
            print(f"{'PASS' if ok else 'FAIL'}  {'[audit6] cd ~/elsewhere is expanded':38s} want=ALLOW got={got}")
            if not ok:
                failures.append("tilde in cd operand")
        else:
            # macOS temp dirs live outside $HOME; assert the expansion directly.
            spec = importlib.util.spec_from_file_location("guard", HOOK)
            guard = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(guard)
            got_dir = guard.target_dir([["cd", "~/somewhere"], ["git", "push"]], ROOT, 1)
            ok = got_dir == os.path.join(home, "somewhere")
            print(f"{'PASS' if ok else 'FAIL'}  {'[audit6] cd ~/elsewhere is expanded':38s} got={got_dir}")
            if not ok:
                failures.append("tilde in cd operand")

    # [audit7] CHAINED `-C` — git applies every one, in order.
    #
    # `argv.index("-C")` saw only the first, so `git -C /tmp/plain -C <this repo>
    # push` was judged against the throwaway directory, found no gate there, and
    # skipped — a bypass of exactly the kind the scope check was added to avoid.
    # Verified against real git: `git -C a -C b rev-parse --show-toplevel` is b.
    with tempfile.TemporaryDirectory() as decoy:
        subprocess.run(["git", "init", "-q", decoy], check=True)
        got = decision(f"git -C {decoy} -C {ROOT} push", cwd=ROOT)
        ok = got == "DENY"
        print(f"{'PASS' if ok else 'FAIL'}  {'[audit7] chained -C targets the last':38s} want=DENY  got={got}")
        if not ok:
            failures.append("chained -C bypass")

    # ...while THIS repo is still gated from anywhere. The scope check must not
    # have become a way out: a push aimed here is judged here, whatever the cwd.
    got = decision(f"git -C {ROOT} push", cwd="/tmp")
    ok = got == "DENY"
    print(f"{'PASS' if ok else 'FAIL'}  {'[audit5] this repo still gated':38s} want=DENY  got={got}")
    if not ok:
        failures.append("scope: own repo still gated")

    print()
    print("ALL PASS" if not failures else f"FAILURES: {failures}")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
