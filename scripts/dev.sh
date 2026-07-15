#!/usr/bin/env bash
#
# Reelmap local dev — one command to run the whole stack on a Mac.
#
#   ./scripts/dev.sh                Boot backend + build & launch the iOS app (default)
#   ./scripts/dev.sh start          Boot backend + Metro only for iOS (fast; after a first build)
#   ./scripts/dev.sh android        Boot backend + build & launch on a connected Android device
#   ./scripts/dev.sh android-start  Boot backend + Metro only for Android (fast; after a first build)
#   ./scripts/dev.sh backend        Boot backend services + queue worker, nothing else
#   ./scripts/dev.sh stop           Stop the backend stack
#
# Backend runs in Docker (Postgres/PostGIS, Redis, Meilisearch, Mailpit, PHP 8.4).
# The API is at http://localhost:8080, Mailpit (captured emails) at http://localhost:8025.
# The mobile app is a custom dev client, so a build runs natively (~2-3 min the first
# time); use the `*-start` modes afterwards for fast JS refresh.
#
# iOS points at localhost. Android runs on a PHYSICAL device, which can't reach
# localhost, so it's pointed at this Mac's LAN IP (phone + Mac must share Wi-Fi).
# The Android map also needs GOOGLE_MAPS_ANDROID_KEY set (see app.config.ts).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="$ROOT/apps/api"
MOBILE_DIR="$ROOT/apps/mobile"
CONTAINER="api-laravel.test-1"
API_URL="${EXPO_PUBLIC_API_URL:-http://localhost:8080}"
MODE="${1:-run}"

log() { printf '\n\033[1;36m▶ %s\033[0m\n' "$1"; }
die() { printf '\033[1;31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

stop_backend() {
  log "Stopping backend services…"
  ( cd "$API_DIR" && docker compose down )
}

# yt-dlp powers full carousel + video image ingestion (T-013). The Sail image
# ships python3 but not yt-dlp, so drop in the standalone binary once per
# container (idempotent). Not fatal if it fails — the pipeline then falls back
# to the oEmbed hero thumbnail. Prod bakes yt-dlp into the image instead.
ensure_yt_dlp() {
  if docker exec "$CONTAINER" sh -lc 'command -v yt-dlp >/dev/null 2>&1'; then
    return
  fi
  log "Installing yt-dlp in the API container (full carousel/video ingestion)…"
  # -f: fail on an HTTP error instead of writing the error body into the binary
  # (which would pass the command -v check next run and silently stay broken).
  docker exec "$CONTAINER" sh -lc \
    'curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp && chmod a+rx /usr/local/bin/yt-dlp' \
    || echo "  (heads up: yt-dlp install failed — photo posts fall back to the oEmbed hero image)"
}

boot_backend() {
  docker info >/dev/null 2>&1 || die "Docker isn't running — start Docker Desktop first."

  log "Booting backend services (Postgres, Redis, Meilisearch, Mailpit, API)…"
  ( cd "$API_DIR" && WWWUSER="$(id -u)" WWWGROUP="$(id -g)" docker compose up -d )

  log "Waiting for the API container…"
  for _ in $(seq 1 45); do
    if docker exec "$CONTAINER" php artisan --version >/dev/null 2>&1; then break; fi
    sleep 2
  done
  docker exec "$CONTAINER" php artisan --version >/dev/null 2>&1 || die "API container never came up."

  log "Running database migrations…"
  docker exec "$CONTAINER" php artisan migrate --force

  ensure_yt_dlp

  # The queue worker drives the share/analysis pipeline AND sends the queued
  # emails (verification, invites). Start one if none is already running.
  if docker exec "$CONTAINER" sh -lc 'ps ax 2>/dev/null | grep -q "[q]ueue:work"'; then
    log "Queue worker already running."
  else
    log "Starting the queue worker…"
    docker exec -d "$CONTAINER" sh -lc \
      'php artisan queue:work redis --queue=ingest,fetch,media,transcribe,analyze,resolve,publish,notifications,default --sleep=1 --tries=2 --timeout=600 >> storage/logs/worker.log 2>&1'
  fi

  printf '\n  API health : %s/api/v1/health\n  Mailpit    : http://localhost:8025\n' "$API_URL"
}

use_node() {
  # Expo SDK 57 tooling needs Node >= 20.19; prefer a modern nvm install if present.
  for v in v22.22.2 v22; do
    if [ -d "$HOME/.nvm/versions/node/$v/bin" ]; then
      export PATH="$HOME/.nvm/versions/node/$v/bin:$PATH"; return
    fi
  done
}

lan_ip() { ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || true; }

launch_ios() {
  use_node; cd "$MOBILE_DIR"
  if [ "$1" = start ]; then
    log "Starting Metro (press i to open iOS). API: $API_URL"
    EXPO_PUBLIC_API_URL="$API_URL" npx expo start --dev-client
  else
    log "Building & launching the iOS app (first run ~2-3 min). API: $API_URL"
    EXPO_PUBLIC_API_URL="$API_URL" npx expo run:ios
  fi
}

launch_android() {
  local ip url; ip="$(lan_ip)"
  [ -n "$ip" ] || die "Couldn't detect this Mac's LAN IP (en0/en1) — connect to Wi-Fi first."
  url="http://$ip:8080"
  log "Android points at $url — make sure your phone is on the SAME Wi-Fi (a physical device can't reach localhost)."
  [ -n "${GOOGLE_MAPS_ANDROID_KEY:-}" ] || echo "  (heads up: GOOGLE_MAPS_ANDROID_KEY is unset — the map screen will be blank on Android)"
  use_node; cd "$MOBILE_DIR"
  if [ "$1" = start ]; then
    EXPO_PUBLIC_API_URL="$url" npx expo start --dev-client
  else
    log "Building & launching on the Android device (needs a device connected — check with: adb devices)."
    EXPO_PUBLIC_API_URL="$url" npx expo run:android --device
  fi
}

case "$MODE" in
  stop)          stop_backend ;;
  backend)       boot_backend ;;
  run)           boot_backend; launch_ios ;;
  start)         boot_backend; launch_ios start ;;
  android)       boot_backend; launch_android ;;
  android-start) boot_backend; launch_android start ;;
  *) die "Unknown mode '$MODE'. Use: run | start | android | android-start | backend | stop" ;;
esac
