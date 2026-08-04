#!/usr/bin/env bash
# PreToolUse(Bash) — refuse `simctl openurl`, which hijacks the owner's app.
#
# `xcrun simctl openurl` does not just navigate: it sets the app's LAUNCH URL,
# and Expo Router replays that on every reload. So an agent that deep-links to a
# screen it is verifying leaves the owner's next Cmd+R landing on that screen
# instead of home — the app appears "stuck on the wrong page".
#
# This was reported THREE times ("stuck in offers", "stuck in new offer",
# "STUCK ON THE WRONG PAGE"), every one of them caused by an agent's own
# verification. Twice the response was a written rule to clean up afterwards.
# Both failed — the second time within minutes, because the offending command
# was the setup step for a screen recording. A rule that depends on remembering
# a cleanup step is not a fix, so the capability is removed instead.
#
# The replacement is strictly better anyway: Maestro navigates by tapping real
# controls, which is what a user does, and sets no launch URL.
#
#   ~/.maestro/bin/maestro test flow.yaml   # launchApp + tapOn
#
# Escape hatch: REELMAP_ALLOW_DEEPLINK=1, for the rare screen with no in-app
# path (testing deep-link handling itself). Even then, finish with a URL-less
# `simctl terminate` + `launch`, which is what actually clears the launch URL,
# and verify with a Cmd+R screenshot.
set -uo pipefail

payload=$(cat)

[ "${REELMAP_ALLOW_DEEPLINK:-}" = "1" ] && exit 0

# Prefer the parsed command, but fall back to the raw payload: if jq is missing
# this guard must still fire rather than silently allowing everything.
if command -v jq >/dev/null 2>&1; then
  cmd=$(printf '%s' "$payload" | jq -r '.tool_input.command // ""')
else
  cmd=$payload
fi

[ -z "$cmd" ] && exit 0

# Quoted spans are stripped before matching, so text that merely NAMES the
# command still runs: `git commit -m "ban simctl openurl"`, a grep for it, a
# heredoc writing these very docs. The DB guard learned this by refusing its own
# commit message; this guard refused the file that tests it. Only an unquoted
# `simctl [flags] openurl` is a real invocation.
bare=$(printf '%s' "$cmd" | sed -e "s/'[^']*'/''/g" -e 's/"[^"]*"/""/g')

FLAGS='(-[^[:space:]]+[[:space:]]+)*'
if ! printf '%s' "$bare" | grep -Eq "simctl[[:space:]]+${FLAGS}openurl"; then
  exit 0
fi

jq -n '{
  hookSpecificOutput: {
    hookEventName: "PreToolUse",
    permissionDecision: "deny",
    permissionDecisionReason: (
      "Blocked: `simctl openurl` sets the app'"'"'s LAUNCH URL, which Expo Router replays on every reload. The owner'"'"'s next Cmd+R will land on the screen you deep-linked to instead of home — they have reported this three times as the app being \"stuck on the wrong page\", and every instance was an agent'"'"'s own verification step.\n\n" +
      "Navigate with Maestro instead — it taps the real controls a user would, and sets no launch URL:\n" +
      "  cat > /tmp/flow.yaml <<'"'"'YAML'"'"'\n" +
      "  appId: pet.one.reelmap\n" +
      "  ---\n" +
      "  - launchApp\n" +
      "  - tapOn: { id: \"map-offers\" }\n" +
      "  - takeScreenshot: /tmp/shot\n" +
      "  YAML\n" +
      "  ~/.maestro/bin/maestro test /tmp/flow.yaml\n\n" +
      "This also verifies the screen is REACHABLE, which a deep link never does — see the Wiring & seams rules in CLAUDE.md.\n\n" +
      "If the screen genuinely has no in-app path (you are testing deep-link handling itself), re-run with REELMAP_ALLOW_DEEPLINK=1 exported, then finish with a URL-less `simctl terminate` + `launch` and confirm with a Cmd+R screenshot that it lands on home."
    )
  }
}'
