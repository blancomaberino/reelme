# T-149 — The guards block prose that merely names the command they forbid

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** — (independent; the guards are checked-in shared automation)
- **Target paths:** `.claude/hooks/guard-simulator-deeplink.sh`,
  `.claude/hooks/guard-destructive-db.sh`,
  `.claude/hooks/tests/guard-simulator-deeplink.test.sh`,
  `.claude/hooks/tests/guard-destructive-db.test.sh`

Found 2026-08-19 while committing the T-137..T-148 wave. The guard refused a
`git commit -F -` because the **commit message** described the command the task
was about. No simulator was involved. It then refused the write of *this file*,
for the same reason.

## Context

`guard-simulator-deeplink.sh` strips quoted spans before matching, precisely so
that text *naming* the command still runs. Its own comment says so:

> Quoted spans are stripped before matching, so text that merely NAMES the
> command still runs: `git commit -m "ban simctl <the subcommand>"`, a grep for
> it, a heredoc writing these very docs. The DB guard learned this by refusing
> its own commit message; this guard refused the file that tests it.

The heredoc case in that list **is not actually covered.** The stripping is:

```sh
bare=$(printf '%s' "$cmd" | sed -e "s/'[^']*'/''/g" -e 's/"[^"]*"/""/g')
```

A heredoc body is not a quoted span. In `git commit -F - <<'EOF' … EOF`, the
`'EOF'` delimiter is itself stripped as a quoted span and the **body is left
intact**, so any sentence in the message naming the command matches the regex
and the commit is denied. The `-m "…"` form survives because the message really
is inside double quotes. `-F -` does not. Neither does `cat > file <<'EOF'`,
which is how these task files get written.

The existing test file covers the `-m` form and stops there
(`tests/guard-simulator-deeplink.test.sh:48`, "commit message naming it").

### Both guards have the hole, by different routes

- **The deeplink guard** anchors on the invocation and relies on quote-stripping
  to avoid prose. A heredoc body defeats the stripping.
- **The DB guard** does no stripping at all — it narrowed the *pattern* instead,
  anchoring on `artisan [flags] migrate:fresh|…`. That is why it stopped
  refusing its own commit messages, and it is the more robust of the two. It is
  not immune: a heredoc commit message containing the literal artisan
  invocation would still be denied.

Nobody has hit the DB case yet. It is the same bug waiting.

## Why it matters more than it looks

The failure mode is not "an agent is mildly inconvenienced." It is that **the
correct response to a false positive is indistinguishable from the incorrect
one.** Both guards publish an environment escape hatch in their own denial
message. An agent blocked while writing a commit message is handed an obvious
way out that is also exactly what CLAUDE.md forbids — "Don't route around the
hook." The right move is to reword the prose; but a guard that fires on
documentation trains agents to reach for the override, and the override also
covers the real invocations it exists to stop.

A guard that cries wolf on its own changelog erodes the guard.

## Implementation

- Strip heredoc bodies before matching, in both guards: a `<<'DELIM' … DELIM`
  or `<<DELIM … DELIM` span should be blanked the way a quoted span is. Watch
  the `<<-` variant and more than one heredoc in a single command.
- Prefer narrowing over stripping where it is available. The DB guard's
  anchor-on-`artisan` approach is the better pattern. Consider whether requiring
  the match to sit in *command position* — line start, or after `;`, `&&`, `||`,
  `|`, `$(` — removes the need to reason about shell quoting at all. If it does,
  take it: a matcher that does not need to parse quoting is worth more than one
  that parses it well.
- Do **not** widen either escape hatch, and do not remove either guard. The
  behaviour they prevent is real and was reported three times. This task is
  about the guards firing on text, not about relaxing them.

## Acceptance criteria

- [ ] A `git commit -F -` whose heredoc message names the forbidden simulator
      command is ALLOWED — proven by running the guard against a real payload,
      not by inspecting the regex
- [ ] The same holds for a heredoc naming the destructive artisan command
      against the DB guard
- [ ] Real invocations are still DENIED after the change: bare, with flags,
      via `xcrun`, and inside `&&` and `;` chains — the existing deny cases all
      still pass
- [ ] Both test files gain the `-F -` heredoc case alongside the existing `-m`
      case, and both suites pass
- [ ] The comment in `guard-simulator-deeplink.sh` no longer claims coverage the
      code does not have — however this is fixed, the comment matches behaviour

## Gotchas

- **The test files cannot contain the literal command**, or they trip the guard
  they test. `guard-simulator-deeplink.test.sh` already solves this with an
  `$OPEN` variable — keep that trick, do not "clean it up."
- Writing the fix means writing prose about the command, which is the very thing
  being blocked. Expect to hit it while working; use a non-Bash write path
  rather than exporting the escape hatch, which is what this task exists to stop
  people doing.
- Verification is running the hook itself:
  `printf '%s' '<json payload>' | .claude/hooks/guard-<name>.sh; echo $?` — no
  simulator, no database, no app.
- Asymmetry worth noting: the deeplink guard falls back to scanning the **raw
  JSON payload** when `jq` is missing, which makes its false-positive surface
  strictly larger in that branch. Any fix must hold with and without `jq`.
