# Map load testing

`GET /map/places` at 10 000 places (T-053, NFR-2).

## Why this endpoint and no other

The map is the app's front door and it **re-queries on every pan**, so it is the
one endpoint whose cost scales with how much the product succeeds. Everything
else is either a single-row read or already bounded by a cursor.

## What is actually asserted

Two different kinds of check, because they answer different questions:

**Query plans — deterministic, run in CI on every PR.** That the bbox predicate
uses `places_location_gist` and that no `Seq Scan` appears for a selective
viewport. `location && ST_MakeEnvelope(…)::geography` is indexable; most of the
natural-looking rewrites are not, and the endpoint would keep returning *correct*
results while degrading to a full scan — invisible at 10 k rows on a laptop and
fatal at a million. The EXPLAIN is built from the real `publiclyVisible()` scope
and the real predicate, not from a copy, so it cannot pass after the endpoint
changes underneath it.

**Wall clock — recorded, asserted only against a deliberately loose ceiling.** A
tight timing assertion in CI is a flaky test, and a flaky test gets deleted. The
ceiling exists to catch something *catastrophic* (a seq scan, an N+1 over 300
pins); the numbers below are the record.

There is also a **query-count** assertion, not a duration: an N+1 over 300 pins
is ~300 individually fast queries, which on a warm local Postgres comes in under
any wall-clock ceiling worth setting — and then falls over on the network round
trips to a managed database.

```bash
docker compose exec -T laravel.test vendor/bin/pest --testsuite=Load
```

## Results

10 000 places, deterministic lattice over ~11 km of Montevideo. Laravel Sail
(Docker Desktop, Apple Silicon) against Postgres 17 + PostGIS in the same
compose stack. 20 iterations after a warm-up, `ANALYZE`d table.

| Viewport | Path | Places in view | p50 | p95 | max |
|---|---|---|---|---|---|
| City, z13 | clustered | ~9 900 | 306 ms | 321 ms | 431 ms |
| District, z13 | clustered | ~200 | 22 ms | 24 ms | 24 ms |
| Block, z16 | pins (capped 300) | ~40 | 20 ms | 22 ms | 22 ms |
| Empty ocean, z13 | clustered | 0 | 8 ms | 10 ms | 10 ms |

**NFR-2 asks for p95 ≤ 600 ms on map bbox/cluster queries. The worst case
measured is 321 ms — inside budget, with roughly 2× headroom**, on a laptop
running the database in Docker alongside the app. Production hardware is not the
constraint here.

### What the numbers say

**The clustered path scales with places IN VIEW, not with table size.** Rows two
and one are the same code path, the same zoom, and the same 10 000-row table —
they differ only in how much of the city the bbox covers, and that is a **14×**
difference. So the number to watch as the table grows is not `COUNT(places)`, it
is how many places sit inside a typical viewport. Ten cities of 10 k places each
behave like the district row, not the city row.

The city row is the pathological case by construction: a bbox containing
essentially the whole dataset. It is dominated by the exact-count plus the
`ST_SnapToGrid` aggregation over every row in the envelope — both unavoidable if
the response is to report a true total.

**An empty viewport costs ~8 ms.** That is the index doing its job: it answers
"no rows" without touching the heap. If this number ever approaches the city
row, the index is not being used and every other measurement here is passing on
small-table luck.

**A seq scan is sometimes the CORRECT plan.** The index assertion originally ran
against the city viewport and failed: with ~99 % of rows inside the envelope,
Postgres picks a sequential scan because it is genuinely cheaper. Postgres was
right and the test was wrong. Index usage is only a meaningful claim where the
predicate is selective — which is also the case that matters in practice, since
a user looks at a neighbourhood.

## Reproducing / regenerating this table

The timings are **printed by the test**, not typed by hand — a hand-maintained
benchmark is stale the day after it is written:

```bash
docker compose exec -T laravel.test vendor/bin/pest --testsuite=Load 2>&1 | grep '\[load\]'
```

The seed (`tests/Load/PlaceSeeder.php`) lays places out on a golden-ratio lattice
rather than randomly. A random layout makes the pin-cap and cluster-count
assertions flaky by construction — one run puts 280 places in the test's block
and the next puts 310, and the failure looks like an endpoint regression.

## Not covered here

- **Concurrency.** These are sequential single-request timings. Contention,
  connection-pool limits and Horizon competing for the same database are a
  staging exercise against real infrastructure (T-055), not a Pest suite.
- **Cold cache.** Every measurement is warm. A production cold start pays
  Postgres buffer-cache misses this cannot simulate on a 10 k-row table that
  fits entirely in memory.
- **The pipeline.** Ingest is queued and asynchronous; its budget is Horizon
  wait times (`config/horizon.php`), covered by the alerting in
  [observability.md](observability.md).
