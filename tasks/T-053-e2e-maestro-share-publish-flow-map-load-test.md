# T-053 — E2E: Maestro share→publish flow + map load test

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-026, T-032
- **Target paths:** `apps/mobile/.maestro/`, `apps/api/tests/Load/`
- **Spec refs:** [05-mobile-app.md#testing](../05-mobile-app.md#7-testing), [ROADMAP.md#m5](../ROADMAP.md#m5--hardening--launch)

## Context

M5 exit criteria require the Maestro E2E green in CI and the map load test documented. The mobile share flow (T-025/T-026) and the Map screen (T-032) are complete, and T-029 already ships the 10k-place seeder and a perf smoke test — this task turns those into a repeatable E2E suite and a real load test with recorded results. App code lives in the separate app repo created by T-001.

## Implementation steps

1. **Mock API for E2E** (`05-mobile-app.md §7.2`): a small deterministic mock server (Node, in `apps/mobile/e2e-mock/` or reuse msw in server mode) replaying `packages/contracts` fixtures for `/api/v1/auth/login`, `/shares`, `/shares/:id` (scripted status progression `pending → fetching → analyzing → review` across successive polls), `/shares/:id/confirm` (returns `published` + place), and `/map/places` (fixture pin). Point the app at it via `EXPO_PUBLIC_API_URL`. No real AI/Stripe/network.
2. **Primary flow `apps/mobile/.maestro/share-to-publish.yaml`** (the one non-negotiable E2E, exactly per spec §7.2): launch → login as seeded test user → open ShareIngest via deep link `reelmap://share-ingest?url=<fixture instagram url>` (Maestro cannot drive the real Instagram share sheet — deep link is the documented E2E entry point; the true share sheet is verified manually per release on a physical device) → tap **Analyze** → assert the stepper advances pending→fetching→analyzing→review → ExtractionReview shows the fixture place name → edit the name → **Confirm** → assert published success screen → tap **View on map** → assert the pin/bottom sheet shows the edited name.
3. **Secondary flows** (per-phase list in §7.2), each its own YAML: `login-logout.yaml`, `duplicate-share-409.yaml` (assert the non-error "You already added this one." treatment), `map-filter-place-detail.yaml`, `follow.yaml`, `redemption-display.yaml`. Add `testID` props in the app where selectors are flaky — prefer `id:` selectors over text.
4. **CI wiring** (`.github/workflows/`): job on merge to `main` — build/reuse an iOS simulator development build (cache the EAS build artifact or use `expo run:ios --configuration Release` on a macOS runner), boot the mock API, run `maestro test .maestro/` (Maestro Cloud is the alternative if runner minutes hurt). The tasks.json acceptance says "against staging": run the suite pointed at the **staging API** with a seeded staging test user for the login + map read flows, keeping the share pipeline steps on the mock (deterministic AI); document both targets in the workflow.
5. **Map load test** (`apps/api/tests/Load/`): use **k6** (script committed as `tests/Load/map-bbox.js`). Setup: staging (or prod-spec local) DB seeded with the T-029 10k-place seeder (`php artisan db:seed --class=PlaceMapSeeder` or equivalent). Scenario: ramp 1→50 VUs over 2 min, 5 min sustained, randomized city-zoom bboxes + zoom levels (mix cluster and pin responses), threshold `http_req_duration{p(95)}<300` per the M2 exit criterion (NFR-2 allows ≤600 ms — record both).
6. **Results doc**: `apps/api/tests/Load/RESULTS.md` — environment (host spec, DB size), k6 summary output, p50/p95/p99, error rate, and observations (index usage via `EXPLAIN ANALYZE` of the hot query, cache behavior). Re-run instructions at the top.
7. **Share-poll load sanity** (small addition): a second k6 scenario hitting `GET /shares/:id` at AnalysisStatus polling cadence for 200 concurrent shares, verifying rate limits (T-051) don't break the polling UX.

## Acceptance criteria

- [ ] `share-to-publish.yaml` passes locally against the mock API: login → deep-link ShareIngest → Analyze → status progression → review with fixture place name → edit + Confirm → published screen → pin visible on map with edited name.
- [ ] Maestro suite runs in CI on merge to `main` (macOS runner + iOS simulator or Maestro Cloud) and is green; staging-targeted run documented and green for login/map flows.
- [ ] Secondary flows exist and pass: login/logout, duplicate-URL 409, map filter + place detail, follow, redemption display.
- [ ] k6 load test against the map bbox endpoint with 10k seeded places: p95 < 300 ms at city zoom (M2 exit bar), results committed to `apps/api/tests/Load/RESULTS.md` with environment details and re-run instructions.
- [ ] Load test script + seeder are re-runnable by any agent with two documented commands.
- [ ] Real share sheet path documented as a manual per-release check on a physical device (Maestro limitation noted in the repo docs).

## Verification

```bash
# E2E locally (iOS simulator with a development build installed):
cd apps/mobile
node e2e-mock/server.js &                       # deterministic fixture API
EXPO_PUBLIC_API_URL=http://localhost:4010 npx expo start --dev-client &
maestro test .maestro/share-to-publish.yaml     # PASS
maestro test .maestro/                          # full suite PASS

# Load test:
cd apps/api
php artisan db:seed --class=PlaceMapSeeder      # 10k places (from T-029)
k6 run tests/Load/map-bbox.js                   # thresholds green: p(95)<300ms
cat tests/Load/RESULTS.md                       # results recorded
```

CI: push a trivial commit to `main`, confirm the `e2e` workflow job runs Maestro and uploads the Maestro debug artifacts (screens/logs) on failure.

## Gotchas

- **Maestro cannot open the OS share sheet** from Instagram — never try; the deep link entry is the spec-blessed substitute, and skipping this manual check before store submission has burned share-extension regressions before.
- Simulator builds don't include a working share extension anyway (`05-mobile-app.md §6.2`) — E2E uses the dev-client simulator build; keep a physical-device checklist item in T-054.
- Maestro + polling screens: use `extendedWaitUntil` with generous timeouts for the status stepper; fixed `waitForAnimationToEnd` sleeps make the suite flaky. Mock server should advance status per-request, not on a wall-clock timer.
- **Load-test data realism**: uniformly-random points make clustering artificially cheap. The seeder must clump places like a real city (e.g. gaussian clusters around neighborhood centroids) or the p95 number is a lie; also run bboxes at multiple zooms, not just one.
- Warm vs cold: run k6 once to warm Postgres/OS caches, measure on the second run; note which one RESULTS.md reports.
- CI macOS runners are slow and expensive — cache the simulator app build; rebuilding the dev client every run makes the job 30+ min.
- Staging runs mutate data — use a dedicated seeded E2E user and idempotent fixtures; never point the suite at production.
