# T-006 — CI: GitHub Actions for api + mobile + contracts

- **Phase:** M0 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-002, T-004, T-005
- **Target paths:** `.github/workflows/`
- **Spec refs:** [01-architecture.md#environments-deployment](../01-architecture.md#environments-deployment)

## Context
With the Laravel API (T-002/T-003), Expo app (T-004), and contracts package (T-005) in place in the app repo (not this plans folder), this task wires GitHub Actions so every push/PR runs all quality gates. This is the M0 exit criterion "CI runs all of the above on every push" and the safety net every later task relies on.

## Implementation steps
1. Create `.github/workflows/ci.yml` triggered on `push` to `main` and `pull_request`. Use three jobs (`api`, `mobile`, `contracts`) with `dorny/paths-filter` (or `on.push.paths` per-workflow split) so unrelated changes skip heavy jobs — but always run everything on `main` pushes (ADR-003: path-filtered CI).
2. **api job** (`runs-on: ubuntu-latest`):
   - Services:
     ```yaml
     services:
       postgres:
         image: postgis/postgis:16-3.4      # MUST be postgis image, not postgres
         env: { POSTGRES_DB: reelmap_test, POSTGRES_USER: reelmap, POSTGRES_PASSWORD: secret }
         ports: ['5432:5432']
         options: >-
           --health-cmd "pg_isready -U reelmap" --health-interval 5s
           --health-timeout 5s --health-retries 10
       redis:
         image: redis:7-alpine
         ports: ['6379:6379']
     ```
   - Steps: checkout → `shivammathur/setup-php@v2` with `php-version: '8.4'`, extensions `pdo_pgsql, pgsql, redis, gd, intl, zip`, coverage off → composer cache → `composer install --no-interaction --prefer-dist` (working-directory `apps/api`) → `cp .env.example .env && php artisan key:generate` → run gates:
     - `vendor/bin/pint --test`
     - `vendor/bin/phpstan analyse --memory-limit=1G`
     - `php artisan migrate --force` then `vendor/bin/pest` with env `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=reelmap_test DB_USERNAME=reelmap DB_PASSWORD=secret REDIS_HOST=127.0.0.1 QUEUE_CONNECTION=redis`.
3. **mobile job**:
   - checkout → `actions/setup-node@v4` (`node-version: 22`, cache npm using root lockfile) → `npm ci` at repo root (workspaces) → in `apps/mobile`:
     - `npm run lint`
     - `npm run typecheck` (`tsc --noEmit`)
     - `npm run test -- --ci`
   - Optional but per 01-architecture §7: `npx expo prebuild --no-install --platform ios` config-plugin sanity check (non-blocking `continue-on-error: false` once stable).
4. **contracts job**:
   - checkout → setup-node → `npm ci` → in `packages/contracts`:
     - `npm run generate` then `git diff --exit-code src/generated` (fail on uncommitted type drift — per 01-architecture §7)
     - `npm run typecheck`
     - `npm test` (schema validation tests incl. valid/invalid fixtures)
5. Set `concurrency: { group: ci-${{ github.ref }}, cancel-in-progress: true }` and sensible `timeout-minutes` (api 15, mobile 15, contracts 5).
6. Add a status badge to the root README. If branch protection is available on the repo, require the three jobs on `main`.
7. Push a branch + PR to exercise the workflow; iterate until all three jobs are green, then merge to `main` and confirm green there.

## Acceptance criteria
- [ ] `api` job runs `pint --test`, `phpstan analyse`, and `pest` against a **Postgres+PostGIS service container** (postgis image) plus Redis; migrations (incl. `CREATE EXTENSION postgis`) succeed in CI.
- [ ] `mobile` job runs `eslint`, `tsc --noEmit`, and `jest` using the workspace install.
- [ ] `contracts` job regenerates TS types and fails on uncommitted diff, and runs the schema validation tests.
- [ ] Workflow triggers on every push and PR; path filtering skips untouched areas on PRs but full suite runs on `main`.
- [ ] All jobs green on `main` (M0 exit criterion).

## Verification
```bash
# locally reproduce what CI runs
cd apps/api && composer lint && composer stan && composer test
cd ../../ && npm ci
npm run lint -w apps/mobile && npm run typecheck -w apps/mobile && npm test -w apps/mobile
npm run generate -w packages/contracts && git diff --exit-code packages/contracts/src/generated && npm test -w packages/contracts

# then
gh pr checks   # on the test PR: 3 checks, all passing
gh run list --branch main --limit 1   # conclusion: success
```

## Gotchas
- **PostGIS in CI**: the plain `postgres` image lacks PostGIS — `CREATE EXTENSION postgis` fails at migrate. Use `postgis/postgis:16-3.4` (or current 16.x tag) and health-check gating; the extensions migration (T-003) runs as the default superuser so it works.
- Service containers listen on `127.0.0.1`, not the Docker hostname — set `DB_HOST=127.0.0.1`, `REDIS_HOST=127.0.0.1` in the pest step env (the app's `.env.example` points at Sail's `pgsql` host).
- PHP 8.4 needs `shivammathur/setup-php`; ubuntu's default PHP is older. Include the `redis` extension or phpredis-based code fails (predis needs nothing).
- npm workspaces: run `npm ci` at the **repo root** (single lockfile); running it inside `apps/mobile` errors or produces a divergent tree.
- `git diff --exit-code` drift check requires `npm run generate` to be deterministic — pin json-schema-to-typescript exactly in `package-lock.json` (banner timestamps or version bumps cause false failures).
- `expo prebuild` in CI may try to install pods — always pass `--no-install`; it also requires `EXPO_NO_TELEMETRY=1` for clean logs.
- Keep pest from needing network: no live HTTP in tests (spec rule NFR-15) — CI has no Ollama/Google/Meta access.
