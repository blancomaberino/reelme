# T-007 — Redis + Horizon queue infrastructure

- **Phase:** M0 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-002
- **Target paths:** `apps/api/config/horizon.php`, `apps/api/config/queue.php`
- **Spec refs:** [01-architecture.md#system-components](../01-architecture.md#system-components)

## Context
The entire M1 analysis pipeline (04-analysis-pipeline §1) is Horizon-supervised jobs on Redis queues; this task provisions that infrastructure while the app repo (not this plans folder) is still small. It gives T-016/T-017/T-018/T-021 named queues to dispatch onto and admins a dashboard. Redis also backs cache and rate limiting per the architecture doc.

## Implementation steps
1. `composer require laravel/horizon` (resolve latest stable at install time), then `php artisan horizon:install`. Ensure a Redis client is available: prefer the `phpredis` extension (Sail has it); else `composer require predis/predis` and `REDIS_CLIENT=predis`.
2. `config/queue.php` / `.env`: `QUEUE_CONNECTION=redis`. Leave the `redis` connection's `queue` default as `default`; per-queue routing happens via `->onQueue(...)` and Horizon supervisors.
3. `config/horizon.php` — define supervisors covering the canonical queue set from 04-analysis-pipeline §1 (this satisfies the task's "ingest, media, analysis, notifications" — `analysis` is the `analyze` queue):
   ```php
   'defaults' => [
       'supervisor-ingest'   => [... 'queue' => ['ingest', 'fetch'],            'balance' => 'auto', 'maxProcesses' => 3],
       'supervisor-media'    => [... 'queue' => ['media', 'transcribe'],        'balance' => 'auto', 'maxProcesses' => 2],
       'supervisor-analyze'  => [... 'queue' => ['analyze', 'resolve', 'publish'], 'balance' => 'auto', 'maxProcesses' => 2],
       'supervisor-default'  => [... 'queue' => ['default', 'notifications'],   'balance' => 'auto', 'maxProcesses' => 2],
   ],
   ```
   Mirror into `environments.production` / `environments.local` (local can use lower `maxProcesses`). Set `'timeout'` per supervisor generously (media jobs later run up to 600s — set media supervisor timeout 900 and remember `retry_after` in `config/queue.php` redis connection must exceed the longest job timeout; set `retry_after => 960`).
4. Gate the dashboard to admins: in `HorizonServiceProvider::gate()`:
   ```php
   Gate::define('viewHorizon', fn ($user) => $user->is_admin);
   ```
   Note: `users.is_admin` lands in T-003; if T-003 isn't merged yet, gate on `false` with a TODO — never leave the default `local`-open gate pointing at a wildcard in non-local envs (the provider's `authorization` already restricts non-local to the gate; keep that).
5. Add the Horizon daemon notes to `apps/api/README.md`: local run `php artisan horizon` (or Sail equivalent), production runs as a Forge daemon restarted via `horizon:terminate` on deploy (01-architecture §7).
6. Scheduler entry `Schedule::command('horizon:snapshot')->everyFiveMinutes()` (metrics graphs need snapshots).
7. Sample job to prove the plumbing: `app/Jobs/PingQueue.php` — implements `ShouldQueue`, `public $queue = 'ingest';` (via `onQueue` in dispatch), writes a cache key `queue:ping:{uuid}`. Keep it: later smoke tests reuse it.
8. Pest tests:
   - `Queue::fake()` test: dispatching `PingQueue::dispatch()->onQueue('ingest')` asserts pushed on the `ingest` queue.
   - Real-execution test: `Bus::dispatchSync(new PingQueue($uuid))` asserts the cache key is written (proves handle() works without a live worker).
   - Config test: assert the horizon config's supervisor queues cover exactly `ingest, fetch, media, transcribe, analyze, resolve, publish, notifications, default` (guards against later queue-name drift vs 04-analysis-pipeline §1).
9. Manual smoke with a live worker (see Verification).

## Acceptance criteria
- [ ] Horizon (latest stable) installed; `php artisan horizon` boots supervisors without error; dashboard reachable at `/horizon`.
- [ ] Dashboard access denied to guests and non-admin users, allowed for `is_admin` users (in every environment, not just via the local-only default).
- [ ] Queues `ingest`, `fetch`, `media`, `transcribe`, `analyze`, `resolve`, `publish`, `notifications` (+ `default`) are assigned to supervisors with sensible balancing (`balance: auto`) and timeouts compatible with the M1 job table (media/transcribe ≥ 600s headroom; `retry_after` > longest timeout).
- [ ] `QUEUE_CONNECTION=redis` is the default in `.env.example`; Redis client (phpredis or predis) available.
- [ ] Sample queued job dispatches to a named queue and its Pest tests pass (Queue::fake assertion + sync execution assertion).
- [ ] `horizon:snapshot` scheduled.

## Verification
```bash
cd apps/api
composer test          # includes queue tests
./vendor/bin/sail up -d
./vendor/bin/sail artisan horizon &                 # or a second terminal
./vendor/bin/sail artisan tinker --execute="App\Jobs\PingQueue::dispatch(Str::uuid()->toString())->onQueue('ingest');"
# Horizon UI http://localhost/horizon (as an is_admin user) shows the job completed on 'ingest'
curl -s -o /dev/null -w '%{http_code}\n' http://localhost/horizon   # 403 (or redirect) when unauthenticated
```

## Gotchas
- `retry_after` (queue config) must be **greater** than the largest job `timeout`, or Horizon re-delivers long-running media jobs mid-flight — the classic duplicate-job bug; the M1 pipeline's idempotency guards assume this is set correctly anyway.
- Horizon requires phpredis or predis; Sail ships phpredis, but CI (T-006) needs the `redis` PHP extension in setup-php — coordinate.
- The default `HorizonServiceProvider` allows everyone in `local`; that's fine locally but never deploy without the `is_admin` gate — write the gate now, not in M5.
- Don't invent queue names: 04-analysis-pipeline §1's job table is canonical (`ingest, fetch, media, transcribe, analyze, resolve, publish`); tasks.json's "analysis" = `analyze`. The config assertion test locks this in.
- `payouts` queue is intentionally absent until M4 (T-045) — add it then, not now.
- Horizon dashboard assets: run `php artisan horizon:publish` after upgrades (add to `composer.json` `post-update-cmd`).

## Log
- **2026-07-09** — Done. **PR #3** (`feat/t007-horizon` → `feat/t005-contracts`, stacked). Horizon ^5.47. All gates green: `composer test` 27 passing / 70 assertions (8 queue tests), Pint (55 files), PHPStan L6. Horizon boots supervisors cleanly; **live round-trip verified** (dispatched `PingQueue` on `ingest`, worker processed + wrote cache key).
- **Implementation notes**:
  - 4 supervisors: ingest/fetch (t/o 120), media/transcribe (t/o 900, mem 256), analyze/resolve/publish (t/o 600, mem 256), default/notifications (t/o 120). `retry_after=960` > 900. Config test locks the exact 9-queue set (no `payouts` yet — M4/T-045).
  - Gate: `viewHorizon` = `is_admin` only. Enforced in all non-local envs; **local stays open by Horizon's own default** (the parent `authorization()` short-circuits on `local` — accepted per the brief's gotcha; Sentinel blocks public/tunnel exposure there). Tests run under `testing` env so the gate is exercised (guest/non-admin 403, admin 200, + `/horizon/api/stats` non-admin 403).
  - `horizon:snapshot` every 5 min (routes/console.php); `horizon:publish` in `post-update-cmd`.
  - **/simplify** clean (kept readable supervisor config). **/security-review** clean — applied both Low notes (precise local-bypass wording, data-endpoint access test). Reviews run via subagents because the `/security-review` skill is bound to the shell cwd (plans repo), not the app repo.
