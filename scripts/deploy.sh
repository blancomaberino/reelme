#!/usr/bin/env bash
#
# Zero-downtime deploy for the Reelmap API (T-055).
#
# Written for Laravel Forge's deploy hook, but it is plain bash and assumes only
# a git checkout with PHP, composer and a running Horizon — nothing Forge-
# specific. Paste it into the site's deploy script, or run it from any deploy
# tool with $FORGE_SITE_PATH set.
#
# ORDER IS THE WHOLE POINT HERE. The sequence below is not arbitrary:
#
#   1. Maintenance mode goes on BEFORE THE PULL — not merely before the
#      migration — and off after, and it is the ONLY thing that must survive a
#      failure, hence the trap. A deploy that dies mid-migration with the site
#      still down is an outage; one that dies with the site up serving a
#      half-migrated schema is worse.
#
#      It used to go on after `composer install`, which left the NEW code
#      serving live traffic against the OLD schema for the length of the
#      install. That was survivable while every migration only ADDED a column
#      the old code ignored. T-157 ended that: `PlaceController::show()` eager-
#      loads a `dishes` relation whose table the same deploy creates, so the
#      window meant `SQLSTATE[42P01] relation "dishes" does not exist` — a 500
#      on every place detail, for as long as composer took.
#   2. Caches are cleared BEFORE composer install and rebuilt AFTER, because a
#      cached config file that references a class the new code deleted makes
#      artisan itself unbootable — including the command you would use to clear
#      it.
#   3. Horizon is terminated LAST. It restarts with the new code; terminating it
#      first would leave a window where the old workers pick up jobs the new
#      migration has already reshaped underneath them.
#
# WHAT THIS SCRIPT CANNOT DO: it does not provision anything, and it has never
# run against real infrastructure — see docs/runbooks/provisioning.md. Treat the
# first deploy as an exercise to be watched, not as a routine one.

set -Eeuo pipefail

SITE_PATH="${FORGE_SITE_PATH:-$(pwd)}"
PHP="${FORGE_PHP:-php}"
COMPOSER="${FORGE_COMPOSER:-composer}"

cd "$SITE_PATH/apps/api"

# Whatever happens after this point, bring the site back up. Without the trap a
# failed migration leaves maintenance mode latched on and the outage lasts until
# somebody notices and runs `artisan up` by hand.
cleanup() {
  local code=$?
  if [ $code -ne 0 ]; then
    echo "::error:: deploy failed (exit $code) — lifting maintenance mode so the OLD code serves"
  fi
  $PHP artisan up || true
  exit $code
}
trap cleanup EXIT

echo "==> Maintenance mode"
# `--secret` keeps a way in: the deployer can hit /<secret> to check a migration
# landed before letting traffic back. It is passed ONLY when DEPLOY_SECRET is
# set, with NO fallback — a default value here would be a guessable bypass path
# live on every deploy, letting anyone who knows it walk past maintenance mode
# into a half-migrated app. An unset variable costs a convenience; a shared
# default costs the thing maintenance mode is for.
#
#   DEPLOY_SECRET=$(openssl rand -hex 16)   # in the deploy environment
if [ -n "${DEPLOY_SECRET:-}" ]; then
  $PHP artisan down --render="errors::503" --retry=15 --secret="$DEPLOY_SECRET"
else
  $PHP artisan down --render="errors::503" --retry=15
fi

echo "==> Pulling"
git -C "$SITE_PATH" pull origin main

echo "==> Clearing compiled caches (before install — see the header)"
$PHP artisan config:clear
$PHP artisan route:clear
$PHP artisan view:clear

echo "==> Installing dependencies"
$COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "==> Migrating"
# --force because there is no TTY. --isolated so two overlapping deploys (a
# retried hook, a second app server) cannot run the same migration twice; it
# needs the shared cache lock the scheduler already relies on.
$PHP artisan migrate --force --isolated

# Derived-projection backfills, INSIDE the outage and immediately after the
# schema they depend on. These are not optional repair tools: T-157's read path
# (place detail `dishes[]`, `?dish=`) switched to the `dishes` table in the same
# release that created it, so an unpopulated table is a live regression — every
# place loses its menu — not a feature waiting to be enabled.
#
# Idempotent by construction (each rewrites a source's whole dish set), so a
# retried deploy is free, and they no-op once the corpus is materialized.
echo "==> Backfilling derived projections"
# NOT fatal, deliberately. The command exits non-zero when it could not
# materialize every source — which is the right answer for a human running it by
# hand, but the wrong one here: under `set -e` it would abort the deploy AFTER
# `migrate` and BEFORE the cache rebuild and worker restart, and the EXIT trap
# would then lift maintenance mode onto the new schema with stale caches. A
# handful of sources whose dishes lag by minutes is a far smaller problem, and
# one source vanishing mid-walk is a routine race against the queue (maintenance
# mode stops HTTP, not Horizon). Re-run the command to close the gap.
if ! $PHP artisan reelmap:dishes:backfill; then
  echo "==> WARNING: dish backfill did not complete. The deploy continues; those"
  echo "    places show no menu until 'php artisan reelmap:dishes:backfill' is re-run."
fi

echo "==> Rebuilding caches"
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

# Sentry wants to know what shipped. Without a release tag every regression
# reads as "always been broken" — see docs/observability.md.
if [ -n "${SENTRY_RELEASE:-}" ]; then
  echo "==> Deploying release ${SENTRY_RELEASE}"
fi

echo "==> Restarting queue workers"
# LAST, and `terminate` not `restart`: it lets in-flight jobs finish on the old
# code and brings the replacements up on the new. The pipeline's jobs run for
# minutes (see config/horizon.php timeouts), so killing them mid-flight would
# strand shares in a non-terminal state.
$PHP artisan horizon:terminate

echo "==> Deploy complete"
# The trap runs `artisan up` on the way out.
