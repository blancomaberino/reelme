---
name: graphify-repo
description: How Reelmap's graphify knowledge graph is built, queried, and refreshed. Use when answering "how does X work / what connects to Y / trace the flow through Z" about this codebase, or when doc/schema edits mean the graph needs a manual rebuild.
---

# Codebase knowledge graph (graphify)

This repo is mapped with **graphify** — a local knowledge graph of the codebase (Tree-sitter AST over all ~800 code files + semantic extraction over the docs). It's a **local dev aid, not a checked-in artifact**: everything lives under `graphify-out/` which is **git-ignored** (it holds machine-specific paths + a cache).

- **Answering "how does X work / what connects to Y / trace the flow through Z" questions:** when `graphify-out/graph.json` exists, prefer **`graphify query "<question>"`** (or the `/graphify` skill) over a cold grep — it already knows the cross-cutting bridges. Top hubs are `Place`, `Share`, `User`; the `@reelmap/contracts` package is the API↔mobile source-of-truth bridge.
- **Keeping it fresh:** a **post-commit hook auto-rebuilds** the graph (AST only — no tokens, no network) after every commit, and a post-checkout hook refreshes it on branch switch. **Code changes need nothing.** Doc/image/schema changes are *not* picked up automatically — run **`/graphify . --update`** (or `graphify update`) manually after meaningful doc edits.
- **Full rebuild from scratch:** `/graphify .` (skips the `assets/` icons and test-fixture videos by design; the `.npmrc` is auto-skipped as sensitive).
- graphify is a **personal/local setup on this machine** (installed via `uv tool install graphifyy`), like `/coderabbit` — it is not wired into CI and imposes nothing on collaborators.
