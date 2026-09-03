#!/usr/bin/env bash
# PreToolUse(Bash) — nothing reaches the repo without an agency audit of the
# code as it stands right now.
#
# WHY. Every gate this project already runs answers a different question. Pint,
# PHPStan, Pest and tsc prove the code does what it says; the line-by-line
# review reads the diff. Neither reads the change the way a mobile engineer, a
# backend architect, a security engineer, a UX architect, a UI designer and a
# test-quality reviewer each read it — and that fan-out is what has actually
# caught things here: an hours block no user could reach, a contract guard that
# could not fail, a schema tightening that would have broken a live response.
#
# The receipt is keyed to HEAD **and** the working tree, so a new commit or an
# uncommitted edit invalidates it. Pushing is covered as well as opening a PR,
# because a PR is "updated" by a push and reviewers do not re-read what they
# have already approved.
#
# Escape hatch: REELMAP_SKIP_AUDIT=1 — for a push the audit does not apply to,
# such as a branch carrying no code. Prefer running the audit.
set -uo pipefail

[ "${REELMAP_SKIP_AUDIT:-}" = "1" ] && exit 0

payload=$(cat)

if command -v jq >/dev/null 2>&1; then
  cmd=$(printf '%s' "$payload" | jq -r '.tool_input.command // ""')
else
  cmd=$payload
fi
[ -z "$cmd" ] && exit 0

# Quoted spans are stripped before matching, so a command that merely NAMES one
# of these still runs — a commit message about opening a PR, a grep for it, a
# heredoc writing this very file. The sibling guards learned this the hard way:
# one refused its own commit message, another refused the file that tests it.
bare=$(printf '%s' "$cmd" | sed -e "s/'[^']*'/''/g" -e 's/"[^"]*"/""/g')

FLAGS='(-[^[:space:]]+[[:space:]]+)*'
action=""
if printf '%s' "$bare" | grep -Eq "gh[[:space:]]+${FLAGS}pr[[:space:]]+${FLAGS}(create|edit|ready|merge)"; then
  action="open or update this PR"
elif printf '%s' "$bare" | grep -Eq "git[[:space:]]+${FLAGS}push"; then
  action="push (which updates the PR)"
fi
[ -z "$action" ] && exit 0

cd "${CLAUDE_PROJECT_DIR:-.}" 2>/dev/null || exit 0
receipt=.claude/state/audit-receipt.json

reason=""
if [ ! -f "$receipt" ]; then
  reason="No agency audit has been recorded for this branch."
else
  head=$(git rev-parse HEAD 2>/dev/null || echo "")
  tree=$(git status --porcelain=v1 2>/dev/null | shasum -a 256 | cut -d' ' -f1)
  if command -v jq >/dev/null 2>&1; then
    r_head=$(jq -r '.head // ""' "$receipt")
    r_tree=$(jq -r '.tree // ""' "$receipt")
  else
    r_head=$(grep -o '"head": *"[^"]*"' "$receipt" | cut -d'"' -f4)
    r_tree=$(grep -o '"tree": *"[^"]*"' "$receipt" | cut -d'"' -f4)
  fi
  if [ "$r_head" != "$head" ]; then
    reason="The audit receipt is for ${r_head:0:8}, but HEAD is now ${head:0:8} — the commit changed after it was audited."
  elif [ "$r_tree" != "$tree" ]; then
    reason="The audit receipt matches HEAD, but the working tree has changed since — uncommitted edits are not covered by it."
  fi
fi
[ -z "$reason" ] && exit 0

jq -n --arg reason "$reason" --arg action "$action" '{
  hookSpecificOutput: {
    hookEventName: "PreToolUse",
    permissionDecision: "deny",
    permissionDecisionReason: (
      "Blocked: " + $reason + "\n\n" +
      "Before you " + $action + ", audit it with the agency agents — run the `audit-agency` skill. It fans six read-only reviewers over `main...HEAD` (mobile, backend, security, UX, UI, test quality) in ONE message so they run concurrently.\n\n" +
      "Fix every 🔴 and 🟡 it surfaces, or get the owner to waive one explicitly, then record the receipt:\n" +
      "  .claude/skills/audit-agency/record-receipt.sh findings-fixed \"<one-line summary>\"\n\n" +
      "The receipt is keyed to HEAD AND the working tree, so commit your fixes BEFORE recording it — a receipt taken over a dirty tree certifies code the audit never saw.\n\n" +
      "This is a separate question from the line-by-line diff review. The agency panel reads the same change as six specialists, and that is what caught the unreachable screen, the contract guard that could not fail, and the schema change that would have broken a live response.\n\n" +
      "Escape hatch, for a push this genuinely does not apply to: REELMAP_SKIP_AUDIT=1."
    )
  }
}'
