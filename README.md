# Reelmap — Project Plan

**Working name:** Reelmap. An app where you share an influencer's restaurant video (Instagram, X, TikTok, YouTube) straight from the platform's share sheet into Reelmap. The app fetches the post, analyzes the caption **and** the video with AI (local model first, OpenRouter fallback with user-selectable models), extracts everything it can about the promoted place (address, cuisine, price range, dishes, vibe…), and pins it on a map with full attribution: who shared it, which influencer made it, and a link to the original post. On top of that: Instagram-like accounts, public influencer maps, and a monetization loop where restaurants publish offers, diners redeem them via QR, and influencers earn a revenue share per attributed visit (TheFork-style).

**Stack:** Laravel 13 REST API (Sanctum, Horizon, Postgres + PostGIS, Meilisearch, Filament) · Expo React Native app (TypeScript, expo-share-intent, react-native-maps, EAS) · monorepo. Version policy: latest stable at implementation time.

## This plan is the product spec AND the build queue

It is written so an autonomous agent (or a human) can build the entire application from these files alone.

| File | What it contains |
|------|------------------|
| [00-product-spec.md](00-product-spec.md) | Vision, personas, numbered FR/NFR requirements, out of scope |
| [01-architecture.md](01-architecture.md) | Tech stack + rationale, components, data flows, repo layout, deployment |
| [02-data-model.md](02-data-model.md) | ERD, every table/column/index/enum, dedup + attribution chains |
| [03-api-design.md](03-api-design.md) | Conventions and every REST endpoint with representative payloads |
| [04-analysis-pipeline.md](04-analysis-pipeline.md) | Ingestion adapters, job chain, local-first/OpenRouter model routing, **the extraction JSON schema** |
| [05-mobile-app.md](05-mobile-app.md) | Expo app architecture, share-sheet flow, every screen, EAS workflow |
| [06-monetization.md](06-monetization.md) | Restaurant program, redemption/attribution mechanics, ledger, payouts |
| [07-risks-decisions.md](07-risks-decisions.md) | Risk register, ADR decision log, open questions |
| [ROADMAP.md](ROADMAP.md) | Phases M0–M5 with scope and testable exit criteria |
| [tasks/tasks.json](tasks/tasks.json) | **Machine-readable task graph** — the single source of truth for status |
| tasks/T-###-*.md | One self-contained brief per task |

## How an agent works this plan

1. **Read `tasks/tasks.json`.** Find the lowest phase (M0 → M5) that still has tasks with `status != "done"`.
2. **Pick a ready task** in that phase: every id in its `depends_on` has `status: "done"`. Prefer the lowest id. Independent ready tasks may be worked in parallel by separate agents as long as their `paths` don't overlap.
3. **Set its `status` to `in_progress` in tasks.json**, then open its `tasks/T-###-*.md` brief and the `spec_refs` it names. The brief + those spec sections contain everything needed.
4. **Implement in the app repo** (the code lives in a separate repository, e.g. `~/Sites/reelmap` — create it at T-001; this plans folder holds only plans).
5. **Verify**: every acceptance criterion in the task file must pass, tests green, linters clean.
6. **Set `status` to `"done"`** in tasks.json (and note deviations from the spec, if any, in the task file under a `## Log` heading). Return to step 1.
7. A phase is finished only when ROADMAP.md's exit criteria for it pass — treat those as integration gates, not suggestions.

Rules: never start a task whose dependencies aren't done; never edit the spec docs to match the code — if reality forces a change, record it as a new ADR in 07-risks-decisions.md first; keep tasks.json valid JSON (it is validated by the check below).

## Validating the task graph

```bash
python3 - <<'EOF'
import json
d = json.load(open('tasks/tasks.json')); tasks = d['tasks']
ids = {t['id'] for t in tasks}
assert len(ids) == len(tasks)
assert all(dep in ids for t in tasks for dep in t['depends_on'])
state, deps = {}, {t['id']: t['depends_on'] for t in tasks}
def visit(n):
    assert state.get(n) != 1, f'cycle at {n}'
    if state.get(n): return
    state[n] = 1; [visit(d) for d in deps[n]]; state[n] = 2
[visit(i) for i in ids]
print(f"OK: {len(tasks)} tasks, acyclic, deps complete")
EOF
```

## Status

- Plan authored: 2026-07-09. All 55 tasks `pending`. Start with **T-001**.
