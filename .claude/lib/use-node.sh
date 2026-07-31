#!/usr/bin/env bash
# Put a new-enough Node on PATH. Source it; don't execute it.
#
#   . "$root/.claude/lib/use-node.sh"; use_node
#
# The host default Node is often older than the floor this repo's tooling needs
# (.nvmrc pins 20.19.4; Expo SDK 57 tooling and tsx both fail below it), so
# prefer a modern nvm install when one is present and otherwise leave PATH alone.
#
# scripts/dev.sh carries its own copy of this logic on purpose — it must run as a
# standalone entry point with no dependency on .claude/. If you change the version
# list here, change use_node() there to match.
use_node() {
  local v
  for v in v22.22.2 v22; do
    if [ -d "$HOME/.nvm/versions/node/$v/bin" ]; then
      export PATH="$HOME/.nvm/versions/node/$v/bin:$PATH"
      return 0
    fi
  done
  return 0
}
