# T-051 — Quotas, rate limits, AI cost dashboard

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-020, T-008
- **Target paths:** `apps/api/app/Http/Middleware/`, `apps/api/app/Filament/Widgets/`
- **Spec refs:** [00-product-spec.md#nfr](../00-product-spec.md#4-non-functional-requirements), [04-analysis-pipeline.md#observability](../04-analysis-pipeline.md#8-observability)

## Context

Cost controls are an NFR (NFR-12 quotas, NFR-13 tracking): T-020 already enforces the per-run cost cap (`AI_MAX_COST_PER_RUN`) and per-user daily AI budget (`AI_DAILY_USER_BUDGET`) inside `ModelRouter`; this task adds the HTTP-layer quotas/rate limits from `03-api-design.md §1` and the admin-facing cost dashboard over `analysis_runs`. Filament exists from T-008. App code lives in the separate app repo created by T-001.

## Implementation steps

1. **Rate limiters** (Redis-backed, defined in a `RateLimiter::for()` block in `AppServiceProvider` or `bootstrap/app.php`), exactly per `03-api-design.md §1`:
   - default authenticated: 60/min per user; unauthenticated public reads: 30/min per IP
   - `POST /shares`: 10/min **and** 100/day per user (share quota — the "N shares/day" of NFR-12, default configurable)
   - auth endpoints: 5/min per IP
   - `POST /redemptions/verify`: 30/min per restaurant account
   - `GET /map/places`: 120/min
   Apply via `throttle:` middleware aliases on the route groups in `apps/api/routes/api.php`.
2. **429 response shape**: ensure the exception renderer converts `ThrottleRequestsException` into the standard error envelope `{"error": {"code": "rate_limited", "message": …, "request_id": …}}` with `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `Retry-After` headers (feature-tested per endpoint group).
3. **Analysis quota surfacing**: extend `GET /api/v1/me` `meta`/payload with quota state — `{analysis: {daily_budget_usd, spent_today_usd, shares_today, shares_daily_limit, resets_at}}` — computed from the Redis counters + `analysis_runs` rollup T-020 introduced. This is what the mobile `quota_exceeded` failure copy and the model picker's "per-model quota bars" (`05-mobile-app.md` screen #15) read.
4. **Runtime configurability (FR-58)**: quota values read from config (`config/ai.php`, env-backed: `AI_DAILY_USER_BUDGET`, `SHARES_DAILY_LIMIT`) with an optional per-user override column consulted first; document keys in `.env.example`.
5. **Filament cost dashboard** (`apps/api/app/Filament/Widgets/`):
   - `AnalysisCostChartWidget` (Filament `ChartWidget`): cost per day for the last 30 days, one dataset per `engine` (`local`/`openrouter`), from `analysis_runs` (`SUM(cost_usd) GROUP BY date, engine`).
   - `AnalysisCostTableWidget`: cost + run count + avg confidence + fallback rate by `model` for a selectable period; top-10 users by spend today.
   - `AnalysisStatsOverview` (`StatsOverviewWidget`): today's spend, fallback rate (runs with `engine = openrouter` ÷ total), avg cost per published place — with the >30% sustained fallback-rate warning color per `04-analysis-pipeline.md §8`.
   Register on the Filament dashboard, admin-only.
6. **Mobile friendly quota errors** (`apps/mobile`): the axios 429 interceptor (already specified in `05-mobile-app.md §1.4`) surfaces a toast; `AnalysisStatus` renders the `quota_exceeded` failure state with reset info from `GET /me` per the §2.5 table; ShareIngest disables Analyze with "Daily limit reached — resets at {time}" when `shares_today >= shares_daily_limit`.
7. **Tests (Pest)**: each limiter returns 429 with correct envelope + headers when exhausted (use `RateLimiter` time travel); `POST /shares` blocks at the daily quota and `GET /me` reflects counts; widget queries return correct aggregates against factory-seeded `analysis_runs`. Mobile: component test for quota-exceeded rendering.

## Acceptance criteria

- [ ] All rate limits from `03-api-design.md §1` enforced server-side via Redis limiters, covered by tests asserting 429 + `rate_limited` code + `X-RateLimit-*`/`Retry-After` headers.
- [ ] Per-user daily share quota (default 100/day, config/env adjustable) and per-user daily AI budget enforced; over-quota shares park per `04-analysis-pipeline.md §3` (`review_reason: quota_exhausted`) rather than erroring opaquely.
- [ ] `GET /api/v1/me` exposes quota usage and reset time for the mobile UI.
- [ ] Filament dashboard shows analysis cost by engine, by model, and by day from `analysis_runs`, plus fallback rate and top spenders; visible to admins only.
- [ ] Mobile shows friendly copy (not a raw error) for both 429 rate limits and `quota_exceeded` shares, including reset info.
- [ ] Quota/limit values are runtime-configurable without code changes (env/config, optional per-user override).

## Verification

```bash
cd apps/api
php artisan test --filter=RateLimit
php artisan test --filter=Quota
php artisan test --filter=CostWidget
# Manual limiter check:
for i in $(seq 1 12); do curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8000/api/v1/shares \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" -d '{"url":"https://www.instagram.com/reel/TEST'$i'/"}'; done
# expect 11th/12th → 429 with Retry-After
```

Manual: `/admin` dashboard as admin → cost widgets render with seeded `analysis_runs` data (seed via factory: mixed engines/models/days); flip `AI_DAILY_USER_BUDGET=0.01` in `.env`, run one OpenRouter-routed share, confirm the next parks in `review` with `review_reason: quota_exhausted` and the mobile AnalysisStatus shows the quota copy.

## Gotchas

- Laravel's default 429 is HTML/plain — the JSON envelope must be forced for `api/*` routes in the exception handler or tests will pass while devices get garbage.
- Named limiters keyed on `$request->user()?->id ?: $request->ip()` — don't key authenticated limits by IP (mobile carriers NAT thousands of users behind one IP).
- Daily counters need an explicit reset boundary: use midnight **UTC** consistently (matches the `quota_exhausted` auto-retry in `04-analysis-pipeline.md §3`) and say so in the mobile copy.
- Redis counter + nightly reconcile from `analysis_runs` can drift — the reconcile job is authoritative; never bill/deny on Redis alone after a flush.
- Widget queries over `analysis_runs` need the existing `(engine, model)` / `finished_at` indexes — aggregate by date on `finished_at`, and cache widget queries (`poll: null` or 60 s) so the admin dashboard doesn't hammer Postgres.
- Don't rate-limit `GET /shares/{id}` polling into oblivion — AnalysisStatus polls every 2.5 s (24/min), which must fit inside the default 60/min budget alongside other requests; consider a separate, higher limiter for share polling.
