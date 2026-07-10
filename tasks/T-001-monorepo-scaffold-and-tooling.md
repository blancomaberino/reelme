# T-001 — Monorepo scaffold and tooling

- **Phase:** M0 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** none
- **Target paths:** `/`, `apps/`, `packages/`, `.github/`
- **Spec refs:** [01-architecture.md#monorepo-file-structure](../01-architecture.md#monorepo-file-structure)

## Context
This is the first task of the project: it creates the application repository itself. NOTE: application code lives in a separate app repo created here — NOT in this plans folder. Everything downstream (Laravel API in T-002, Expo app in T-004, contracts package in T-005, CI in T-006) lands inside the directory layout this task establishes, so the structure must match `01-architecture.md` §6 exactly: `apps/api`, `apps/mobile`, `packages/contracts`.

## Implementation steps
1. Create a new repository directory named `reelmap` (outside this plans folder), run `git init -b main`.
2. Create the skeleton directories with placeholder `README.md` files (git cannot track empty dirs):
   - `apps/api/README.md` — one paragraph: "Laravel 13 API — scaffolded in T-002."
   - `apps/mobile/README.md` — "Expo React Native app — scaffolded in T-004."
   - `packages/contracts/README.md` — "Shared JSON Schemas + generated TS types — built in T-005."
   - `.github/workflows/.gitkeep` — CI arrives in T-006.
3. Root `package.json` with npm workspaces per the architecture doc (the Laravel app is NOT a workspace — Composer manages it):
   ```json
   {
     "name": "reelmap",
     "private": true,
     "workspaces": ["apps/mobile", "packages/contracts"]
   }
   ```
4. Root `README.md` explaining: project one-liner, monorepo layout (table of `apps/api`, `apps/mobile`, `packages/contracts`), local setup pointers (API: Sail or Herd, see `apps/api/README.md`; mobile: EAS dev client required — Expo Go will not work; contracts: `npm run generate`), and the convention that `packages/contracts` JSON Schemas are the single source of truth for API/mobile types.
5. Root `.gitignore` combining Laravel + Expo/Node needs. Minimum entries: `node_modules/`, `vendor/`, `.env`, `.env.*.local`, `apps/api/.env`, `apps/api/storage/*.key`, `apps/api/public/storage`, `apps/api/.phpunit.cache`, `.expo/`, `apps/mobile/ios/`, `apps/mobile/android/` (prebuild artifacts — managed workflow), `dist/`, `*.log`, `.DS_Store`, `.idea/`, `.vscode/` (allow `.vscode/extensions.json` if desired).
6. Add an `.editorconfig` (4-space PHP, 2-space TS/JSON, LF, final newline).
7. Commit: `git add -A && git commit -m "chore: monorepo scaffold (apps/api, apps/mobile, packages/contracts)"`.

## Acceptance criteria
- [ ] Repo contains `apps/api/`, `apps/mobile/`, `packages/contracts/` each with a placeholder `README.md` describing what will live there and which task fills it.
- [ ] `.github/workflows/` directory exists (empty except `.gitkeep`).
- [ ] Root `README.md` explains the monorepo layout and local setup entry points for API, mobile, and contracts.
- [ ] Root `package.json` declares npm workspaces `apps/mobile` and `packages/contracts`; `apps/api` is intentionally excluded.
- [ ] Git is initialized on branch `main` with an initial commit; `.gitignore` covers Laravel (`vendor/`, `.env`, `storage` keys) and Expo (`node_modules/`, `.expo/`, prebuild `ios/`/`android/` dirs).
- [ ] Directory names match `01-architecture.md` §6 exactly (no `backend/`, `app/`, or other variants).

## Verification
```bash
cd reelmap
git log --oneline            # ≥1 commit on main
ls apps/api apps/mobile packages/contracts   # each lists README.md
cat package.json | python3 -m json.tool       # valid JSON, workspaces present
git check-ignore -v apps/api/.env node_modules  # both matched by .gitignore
```

## Gotchas
- Do NOT scaffold Laravel or Expo here — `create-expo-app` / `composer create-project` refuse non-empty directories in awkward ways; T-002/T-004 handle scaffolding into the placeholder dirs (they may need to scaffold into a temp dir and move contents over the placeholder README).
- Keep `apps/api` out of npm workspaces; hoisting Node deps into a Laravel app causes confusion and the API has no Node build step at M0.
- Ignore `apps/mobile/ios` and `apps/mobile/android`: Expo managed workflow regenerates them via prebuild; committing them breaks config-plugin-driven builds.
- This plans repo (`/Users/marce/Sites/plans`) is documentation only — never commit application code here.

## Log
- **2026-07-09** — Done. App repo created at `~/Sites/reelmap` (`git init -b main`), 2 commits. Skeleton dirs `apps/api`, `apps/mobile`, `packages/contracts` each with a placeholder README naming the task that fills them; `.github/workflows/.gitkeep` in place. Root `package.json` declares workspaces `apps/mobile` + `packages/contracts` (api excluded), root `README.md` documents layout + setup, `.editorconfig` (4-space PHP / 2-space TS-JSON) added.
- Deviation from step 5: `.gitignore` uses `node_modules` (no trailing slash) instead of `node_modules/` so the brief's `git check-ignore -v node_modules` verification matches — a trailing-slash pattern only matches paths git already knows are directories. Functionally identical for ignoring the dir.
