#!/usr/bin/env bash
# stdio entry point for the Laravel Boost MCP server.
#
# Boost normally runs as `php artisan boost:mcp` on the host — impossible here:
# local PHP is 8.2 and this codebase needs 8.4+. So it runs inside the Sail
# container instead, which is also where the app's real config, DB connection and
# routes live, so tinker/database-query/list-routes all reflect actual dev state.
#
# Requires the stack to be up: ./scripts/dev.sh backend
set -uo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)

exec docker compose -f "$root/apps/api/compose.yaml" exec -T laravel.test php artisan boost:mcp
