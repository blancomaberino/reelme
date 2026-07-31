---
name: dev-environment
description: Start, stop, or run the Reelmap local dev environment (Docker API stack, queue worker, Expo iOS/Android dev client) via ./scripts/dev.sh. Use when asked to "start the environment(s)", "spin up / run everything", "boot the backend", or "run the app locally".
---

# Reelmap local dev environment

When the user asks to **"start the environment(s)"**, "spin up / run everything", "boot the backend", or "run the app locally", use **`./scripts/dev.sh`** (repo root) — do **not** hand-roll `docker compose` + worker + expo commands:

- `./scripts/dev.sh backend` — boots the Docker stack (Postgres/PostGIS, Redis, Meilisearch, Mailpit, PHP 8.4 API on **`:8080`**), runs migrations, and starts the **queue worker**. The worker is required for the **share/analysis pipeline AND queued emails** (email verification, invites) — without it, shares never publish and no mail is sent. This mode is non-interactive; **the agent can run it directly**, then confirm `GET http://localhost:8080/api/v1/health` → 200.
- `./scripts/dev.sh` (default `run`) — the above **plus** a native iOS build + launch of the Expo **dev client** (custom native app, not Expo Go) with `EXPO_PUBLIC_API_URL=http://localhost:8080` wired. First build is ~2–3 min; `start` skips the build (Metro only, fast) after a first `run`; `stop` tears the stack down.
- `./scripts/dev.sh android` (+ `android-start`) — builds/launches on a connected **physical Android device**. A phone can't reach `localhost`, so the script points it at this Mac's **LAN IP** (`ipconfig getifaddr en0`) — the phone and Mac must share Wi-Fi. Android's map is Google Maps (iOS is Apple Maps), so it needs **`GOOGLE_MAPS_ANDROID_KEY`** set (wired conditionally in `app.config.ts`; the map is blank without it, everything else works). Needs Android Studio/SDK + a device with USB debugging (`adb devices`).
- The build/launch modes are long-running and interactive — have the **user** run them in their terminal (suggest `! ./scripts/dev.sh`, `! ./scripts/dev.sh android`, etc.). The agent typically runs `./scripts/dev.sh backend` and lets the user launch the device.
- Captured emails (verification codes, invites) are viewable in **Mailpit at http://localhost:8025**.
- The script selects Node 22 from nvm automatically (Expo SDK 57 tooling needs Node ≥ 20.19; the host default may be older).

> ⚠️ **Never run `php artisan migrate:fresh` (or `db:wipe`) against the dev DB** — artisan's default connection is the dev Postgres, so it **wipes dev data**. Use plain `migrate` on dev; the Pest suite uses a separate testing database. Only wipe dev when the user explicitly asks (e.g. "clear the DB"). (This rule also lives in the root `CLAUDE.md`, where it is always loaded.)

Further detail: `apps/api/README.md`.
