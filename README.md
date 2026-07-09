# Reelmap

Share an influencer's restaurant video (Instagram, X, TikTok, YouTube) straight from the platform's share sheet into Reelmap. The app fetches the post, analyzes the caption **and** the video with AI (local model first, OpenRouter fallback), extracts everything it can about the promoted place, and pins it on a map with full attribution. On top of that: Instagram-like accounts, public influencer maps, and a restaurant offer/redemption loop that pays influencers a revenue share per attributed visit.

> The product spec and build plan live in a separate `plans/reelmap` repository. This repository holds the **application code only**.

## Monorepo layout

| Path | What lives here | Managed by |
|------|-----------------|------------|
| [`apps/api`](apps/api) | Laravel 13 REST API — Sanctum, Horizon, Postgres + PostGIS, Meilisearch, Filament | Composer |
| [`apps/mobile`](apps/mobile) | Expo React Native app (TypeScript) — share intent, maps, EAS builds | npm workspace |
| [`packages/contracts`](packages/contracts) | Shared JSON Schemas + generated TS types (single source of truth) | npm workspace |

`apps/api` is **intentionally excluded** from npm workspaces — the Laravel app has no Node build step and Composer manages its dependencies.

## Local setup

- **API** — Laravel Sail (Docker: postgres+postgis, redis, meilisearch, mailpit) or Laravel Herd. See [`apps/api/README.md`](apps/api/README.md).
- **Mobile** — a custom **EAS dev client is required**; Expo Go will not work (share intent + native maps need config plugins). See [`apps/mobile/README.md`](apps/mobile/README.md).
- **Contracts** — `npm run generate` in `packages/contracts` regenerates TS types from the schemas.

## Contracts are the source of truth

The JSON Schemas in `packages/contracts/schemas` define every shared data shape. The API contract-tests its JSON resources against them and the mobile app generates its types from them — change a shape in one place, regenerate, and both sides stay in sync.
