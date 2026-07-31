#!/usr/bin/env bash
# PostToolUse(Write|Edit) — run Pint on the PHP file that was just written.
#
# `composer lint` (pint --test) is a required gate, and local PHP is 8.2 — too old
# for this codebase — so formatting has to go through the Sail container. Doing it
# per-edit means the lint gate is never the thing that fails the pre-PR pass.
#
# Silent no-op when the file was already well formatted, or when the stack is down.
set -uo pipefail

file=$(jq -r '.tool_response.filePath // .tool_input.file_path // ""')
[ -z "$file" ] && exit 0

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
api="$root/apps/api"

case "$file" in
  "$api"/*.php) ;;
  *) exit 0 ;;
esac
case "$file" in
  "$api"/vendor/*|"$api"/storage/*|"$api"/bootstrap/cache/*) exit 0 ;;
esac

rel=${file#"$api"/}
before=$(cksum <"$file" 2>/dev/null) || exit 0

docker compose -f "$api/compose.yaml" exec -T laravel.test ./vendor/bin/pint "$rel" >/dev/null 2>&1 || exit 0

after=$(cksum <"$file" 2>/dev/null) || exit 0
[ "$before" = "$after" ] && exit 0

jq -n --arg rel "$rel" '{
  hookSpecificOutput: {
    hookEventName: "PostToolUse",
    additionalContext: ("Pint reformatted apps/api/" + $rel + " on disk. Re-read it before your next Edit — the file no longer matches exactly what you wrote.")
  }
}'
