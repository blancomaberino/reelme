#!/usr/bin/env bash
# PostToolUse(Write|Edit) — keep packages/contracts/src/generated in step with the schemas.
#
# The generated TypeScript is COMMITTED, and CI fails the `contracts` job when it
# drifts (it regenerates and runs `git diff --exit-code`). Editing a schema without
# regenerating is therefore a guaranteed red build, discovered minutes later on push.
# This closes the loop the moment the schema is saved.
#
# Fires only for the canonical schema inputs; everything else exits silently.
set -uo pipefail

file=$(jq -r '.tool_response.filePath // .tool_input.file_path // ""')
[ -z "$file" ] && exit 0

case "$file" in
  */packages/contracts/schemas/*.json|*/packages/contracts/extraction.schema.json) ;;
  *) exit 0 ;;
esac

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

# tsx and json-schema-to-typescript need Node >= the .nvmrc floor.
# shellcheck source-path=SCRIPTDIR
# shellcheck source=../lib/use-node.sh
. "$root/.claude/lib/use-node.sh"
use_node

out=$(cd "$root" && npm run --silent generate -w packages/contracts 2>&1)
status=$?

if [ $status -ne 0 ]; then
  jq -n --arg out "$out" '{
    hookSpecificOutput: {
      hookEventName: "PostToolUse",
      additionalContext: ("Contract codegen FAILED after this schema edit — the schema is likely invalid. CI will fail the `contracts` job until this passes.\n\n" + $out)
    }
  }'
  exit 0
fi

changed=$(cd "$root" && git status --porcelain -- packages/contracts/src/generated 2>/dev/null)
[ -z "$changed" ] && exit 0

jq -n --arg changed "$changed" '{
  hookSpecificOutput: {
    hookEventName: "PostToolUse",
    additionalContext: ("Regenerated packages/contracts/src/generated from the edited schema. These files are committed and CI checks them for drift — include them in your commit. Changed:\n" + $changed)
  }
}'
