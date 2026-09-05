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

# Whatever happens after this point, bring the site back up — but ONLY when the
# checkout and the schema still agree. Without the trap a failed migration
# leaves maintenance mode latched on and the outage lasts until somebody
# notices; without the gate below, the trap itself becomes the outage.
#
# `DEPLOY_STATE` tracks whether lifting maintenance mode is a safe assertion:
#
#   consistent  — code and schema agree. Either the tree has not been touched yet
#                 (old code, old schema — note the pull is split into fetch and
#                 merge precisely so this covers the fetch) or the migration
#                 finished (new code, new schema). Lifting is correct.
#   code-ahead  — the pull landed but the migration has not run. Lifting here
#                 serves NEW code against an OLD schema, which is the state this
#                 whole ordering exists to prevent: T-157's place detail
#                 eager-loads a `dishes` relation whose table the migration
#                 creates, so every request 500s with 42P01 — and unlike a
#                 bounded install window, it lasts until a human intervenes.
#
# So a failure between the pull and the migration leaves the site DOWN and says
# so loudly. A 503 with a Retry-After is a bad ten minutes; a 500 on every place
# detail is a bad afternoon nobody is paged for.
DEPLOY_STATE="consistent"

cleanup() {
  local code=$?
  if [ $code -ne 0 ]; then
    if [ "$DEPLOY_STATE" = "code-ahead" ]; then
      echo "::error:: deploy failed (exit $code) AFTER the pull and BEFORE the migration."
      echo "::error:: LEAVING THE SITE IN MAINTENANCE MODE ON PURPOSE — the new code cannot"
      echo "::error:: run against this schema. Recover by finishing the deploy:"
      echo "::error::   cd $SITE_PATH/apps/api && $PHP artisan migrate --force && $PHP artisan up"
      echo "::error:: or by rolling the checkout back to the previous commit and running 'artisan up'."
      exit $code
    fi
    echo "::error:: deploy failed (exit $code) — lifting maintenance mode so the OLD code serves"
    # The likeliest cause now that the merge is --ff-only: a box an older,
    # plain-`git pull` version of this script left with a local merge commit on
    # main. That box fails here on EVERY future deploy, permanently, while the
    # site happily serves old code — deploys just silently stop landing.
    if ! git -C "$SITE_PATH" merge-base --is-ancestor HEAD origin/main 2>/dev/null; then
      echo "::error:: This checkout has DIVERGED from origin/main, so --ff-only cannot advance it."
      echo "::error:: Recover with:  git -C $SITE_PATH fetch origin && git -C $SITE_PATH reset --hard origin/main"
    fi
  fi

  # Not `|| true`: `artisan up` needs a bootable app, and the one place it may
  # not have one is a half-written vendor/ from a failed install. Swallowing
  # that leaves the site 503 with no signal and no clue that the fix is to
  # delete the maintenance file by hand.
  if ! $PHP artisan up; then
    echo "::error:: could not lift maintenance mode (artisan itself will not boot)."
    echo "::error:: The site is serving 503. Recover with:"
    echo "::error::   rm -f $SITE_PATH/apps/api/storage/framework/maintenance.php $SITE_PATH/apps/api/storage/framework/down"
  fi
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
# Split deliberately into fetch + merge, because `git pull` is both and the flag
# below has to sit exactly between them.
#
# A fetch cannot move the working tree — it only writes objects and refs — so
# everything up to here can fail with the checkout still matching the schema,
# and the trap can safely lift maintenance mode.
git -C "$SITE_PATH" fetch origin main

# The merge is the step that can move the tree, so the flag goes on BEFORE it
# and not before the fetch. Two orderings were wrong before this one: setting it
# before the whole pull latched the site down for a failed FETCH that changed
# nothing, and setting it after the whole pull left a window where a merge that
# died PART-WAY (disk full, EACCES, an OOM kill mid-checkout — git exits
# non-zero with some files already on the new commit) still looked consistent,
# so the trap lifted maintenance onto a half-updated checkout.
DEPLOY_STATE="code-ahead"

# --ff-only: a deploy must never produce a merge commit, or stop half-way
# through a conflicted merge with the tree in neither state. A server whose
# checkout has drifted now fails loudly here, on the OLD code, instead of
# deploying an untested merge.
git -C "$SITE_PATH" merge --ff-only FETCH_HEAD

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

# Schema now matches the checkout: lifting maintenance mode is safe again.
DEPLOY_STATE="consistent"

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

# T-158's open-now filter is a semi-join onto `place_open_periods`, so an
# unpopulated table does not degrade the listing — it EMPTIES it: every place
# reads as "hours unknown" and none of them are open. Same non-fatal treatment
# and the same reason as above.
if ! $PHP artisan reelmap:open-periods:backfill; then
  echo "==> WARNING: open-period backfill did not complete. The deploy continues;"
  echo "    those places cannot be found by 'open now' until"
  echo "    'php artisan reelmap:open-periods:backfill' is re-run."
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
