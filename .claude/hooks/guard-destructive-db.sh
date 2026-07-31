#!/usr/bin/env bash
# PreToolUse(Bash) — refuse commands that would wipe the dev Postgres.
#
# artisan's default connection is the *dev* database, so `migrate:fresh`,
# `db:wipe`, `migrate:refresh` and `migrate:reset` destroy real dev data.
# CLAUDE.md forbids them in prose; this makes the rule enforceable.
#
# Plain `migrate` is untouched — that is the sanctioned way to move dev forward.
#
# There is exactly ONE escape hatch: export REELMAP_ALLOW_DB_WIPE=1.
#
# Deliberately NOT trusted as escape hatches (both were verified against this
# project and neither protects the dev database):
#   --env=testing     There is no apps/api/.env.testing, so Laravel falls back to
#                     .env and resolves to database `reelmap` — the DEV database.
#   --database=testing  `--database` names a *connection*, and the configured
#                     connections are sqlite/mysql/mariadb/pgsql/sqlsrv. There is
#                     no `testing` connection, so this never meant what it looked
#                     like. The Pest suite selects the test DB via phpunit.xml
#                     (DB_DATABASE=testing), not via either flag.
#
# Matching requires an actual artisan INVOCATION — the destructive verb has to
# follow `artisan` with only flags in between. Merely naming the verb is fine, so
# `git commit` messages, greps and docs that discuss these commands still work.
# (An earlier revision matched anywhere in the command text and refused its own
# commit message; hence the tighter anchor and the cases covering it.)
set -uo pipefail

payload=$(cat)

[ "${REELMAP_ALLOW_DB_WIPE:-}" = "1" ] && exit 0

# Prefer the parsed command, but fall back to scanning the raw payload: if jq is
# missing, this guard must still fire rather than silently allowing everything.
if command -v jq >/dev/null 2>&1; then
  cmd=$(printf '%s' "$payload" | jq -r '.tool_input.command // ""')
else
  cmd=$payload
fi

[ -z "$cmd" ] && exit 0

# Anchored on `artisan` so only real invocations match:
#   artisan [flags] migrate:fresh|migrate:refresh|migrate:reset|db:wipe
#   artisan [flags] migrate [flags] --fresh
# `--fresh` needs its own arm because it is a flag on other commands too.
FLAGS='(-[^[:space:]]+[[:space:]]+)*'
if ! printf '%s' "$cmd" | grep -Eq \
    "artisan[[:space:]]+${FLAGS}(migrate:(fresh|refresh|reset)|db:wipe)|artisan[[:space:]]+${FLAGS}migrate[[:space:]]+[^|;&]*--fresh"; then
  exit 0
fi

jq -n '{
  hookSpecificOutput: {
    hookEventName: "PreToolUse",
    permissionDecision: "deny",
    permissionDecisionReason: (
      "Blocked: this destroys the dev database. artisan defaults to the dev Postgres connection (database `reelmap`), so migrate:fresh / migrate:refresh / migrate:reset / db:wipe drop real dev data — places, shares, users, media. See the golden rule in CLAUDE.md.\n\n" +
      "Instead:\n" +
      "  • To apply new migrations on dev: docker compose -f apps/api/compose.yaml exec -T laravel.test php artisan migrate\n" +
      "  • The Pest suite already targets its own `testing` database via phpunit.xml — it never needs a dev wipe.\n\n" +
      "Do NOT reach for --env=testing or --database=testing to get around this. Neither points at the test database: there is no .env.testing (so --env=testing resolves to the DEV database), and `testing` is not one of the configured connections.\n\n" +
      "If the user explicitly asked to clear the dev database, ask them to re-run with REELMAP_ALLOW_DB_WIPE=1 exported."
    )
  }
}'
