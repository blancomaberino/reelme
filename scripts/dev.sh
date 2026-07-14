#!/usr/bin/env bash
#
# Reelmap local dev — one command to run the whole stack on a Mac.
#
#   ./scripts/dev.sh          Boot backend + build & launch the iOS app (default)
#   ./scripts/dev.sh start    Boot backend + start Metro only (fast; after a first build)
#   ./scripts/dev.sh backend  Boot backend services + queue worker, nothing else
#   ./scripts/dev.sh stop     Stop the backend stack
#
# Backend runs in Docker (Postgres/PostGIS, Redis, Meilisearch, Mailpit, PHP 8.4).
# The API is at http://localhost:8080, Mailpit (captured emails) at http://localhost:8025.
# The mobile app is a custom dev client, so `run` does a native build (~2-3 min the
# first time); use `start` afterwards for fast JS refresh.

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

  # The queue worker drives the share/analysis pipeline AND sends the queued
  # emails (verification, invites). Start one if none is already running.
  if docker exec "$CONTAINER" sh -lc 'ps ax 2>/dev/null | grep -q "[q]ueue:work"'; then
    log "Queue worker already running."
  else
    log "Starting the queue worker…"
    docker exec -d "$CONTAINER" sh -lc \
      'php artisan queue:work redis --queue=ingest,media,analysis,notifications,default --sleep=1 --tries=2 --timeout=600 >> storage/logs/worker.log 2>&1'
  fi

  printf '\n  API health : %s/api/v1/health\n  Mailpit    : http://localhost:8025\n' "$API_URL"
}

launch_mobile() {
  # Expo SDK 57 tooling needs Node >= 20.19; prefer a modern nvm install if present.
  for v in v22.22.2 v22; do
    if [ -d "$HOME/.nvm/versions/node/$v/bin" ]; then
      export PATH="$HOME/.nvm/versions/node/$v/bin:$PATH"; break
    fi
  done

  cd "$MOBILE_DIR"
  if [ "$MODE" = "start" ]; then
    log "Starting Metro (press i to open iOS). API: $API_URL"
    EXPO_PUBLIC_API_URL="$API_URL" npx expo start --dev-client
  else
    log "Building & launching the iOS app (first run ~2-3 min). API: $API_URL"
    EXPO_PUBLIC_API_URL="$API_URL" npx expo run:ios
  fi
}

case "$MODE" in
  stop)    stop_backend ;;
  backend) boot_backend ;;
  start|run) boot_backend; launch_mobile ;;
  *) die "Unknown mode '$MODE'. Use: run | start | backend | stop" ;;
esac
