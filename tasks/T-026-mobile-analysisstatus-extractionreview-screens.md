# T-026 — Mobile: AnalysisStatus + ExtractionReview screens

- **Phase:** M1 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-025, T-024
- **Target paths:** `apps/mobile/app/share/` (routes live at `app/shares/[id]/status.tsx` and `app/shares/[id]/review.tsx`; feature components under `src/features/ingest/`)
- **Spec refs:** [05-mobile-app.md#screen-inventory](../05-mobile-app.md#3-screen-inventory), [04-analysis-pipeline.md#user-review-step](../04-analysis-pipeline.md#7-user-review-step)

## Context

ShareIngest (T-025) submits shares and navigates to `/shares/[id]/status`; the API exposes `GET /shares/:id` with status history/extraction/candidates and `PATCH /shares/:id` for corrections + publish (T-016/T-024). This task builds the two screens that complete the mobile core loop: live pipeline progress and the extraction correction form. App code lives in the separate app repo created by T-001 (`apps/mobile`), NOT this plans folder.

## Implementation steps

1. **AnalysisStatus** (`app/shares/[id]/status.tsx`):
   - `useQuery(queryKeys.shares.detail(id))` on `GET /api/v1/shares/:id`, typed from `@reelmap/contracts`, with:
     ```ts
     refetchInterval: (q) => isTerminal(q.state.data?.status) ? false : 2500
     // isTerminal: status in {'review','published','failed','rejected'}
     ```
     Polling is primary; push (T-027) is the left-the-app recovery, and `share.*` notifications invalidate `['shares', id]` so an open screen updates instantly.
   - Vertical stepper mirroring the pipeline: `pending → fetching → analyzing → review → published/failed`, current stage animated, past stages checked (drive off `status_history` from the API).
   - Terminal handling: `review` → auto `router.replace('/shares/[id]/review')`; `published` → success screen (place card + confetti-lite, attribution rendered as it will appear publicly, buttons **View on map** → `/places/:id` and **Share another**); `failed` → failure sheet.
2. **Failure states** (table-driven per §2.5, keyed on `share.failure_reason` enum from contracts):
   | reason | copy + action |
   |---|---|
   | `unsupported_url` | back to ShareIngest |
   | `fetch_failed` | "Couldn't load the post…" + **Retry** (`POST /shares/:id/retry`) |
   | `private_post` | two-option sheet from T-025 (link account / add manually) |
   | `not_a_restaurant` | **Add manually** → ExtractionReview with empty fields |
   | `analysis_failed` | **Retry** + link to `/settings/ai-model` |
   | `quota_exceeded` | quota reset info from `GET /me` |
   Every failed share stays reachable from Profile → "My shares" for later retry.
3. **ExtractionReview** (`app/shares/[id]/review.tsx`):
   - Editable form of every field in `share.analysis.extraction`: place name, category, cuisines, address, price range (1–4 segmented), dishes, vibe/dietary tags, influencer handle + platform, original post URL (read-only). Render per-field AI confidence with a warning tint below a threshold (use `confidence.per_field` from the extraction payload).
   - Evidence panel: caption/transcript quotes + referenced keyframes shown alongside the form (04 §7).
   - **Map pin adjust**: embedded `react-native-maps` `MapView` centered on extracted lat/lng with a **fixed center crosshair — user pans the map under the pin** (not marker dragging); read the final region center on confirm. "Search address instead" fallback hits the backend geocode endpoint.
   - **Dedupe candidate picker**: when the share carries candidate places (`review_reason: ambiguous_place`), show "Is this the same place?" at the top with candidates on a mini-map; selecting one switches the submit payload to attach to that `place_id`/`google_place_id` instead of creating a new place.
   - Submit **Confirm** → mutation `PATCH /api/v1/shares/:id` with edited extraction (+ candidate override or adjusted pin) and `{"action": "publish"}` → on success invalidate `['shares', id]` + `['places','map']` and show the published success state. **Discard** → `DELETE /shares/:id` with confirmation.
   - 422 responses map Laravel validation errors onto fields via the T-010 interceptor normalization.
4. **Component tests** (jest-expo + RNTL + msw fixtures typed from `@reelmap/contracts`):
   - AnalysisStatus: polling stops on each terminal status; table-driven test asserting every `failure_reason` renders its mapped copy/action; `review` auto-navigates.
   - ExtractionReview: form prefills from fixture extraction; 422 errors land on the right fields; confirm mutation sends edited values + `action: publish`; candidate selection switches payload shape; low-confidence tinting applied.

## Acceptance criteria

- [ ] AnalysisStatus polls `GET /shares/:id` every 2.5 s, stops at `review`/`published`/`failed`, and renders the stage stepper with the current stage animated.
- [ ] All terminal states handled: `review` auto-navigates to ExtractionReview, `published` shows the success screen with View-on-map, `failed` shows the correct copy/action for every `failure_reason` (incl. retry and private-post/manual routes).
- [ ] ExtractionReview presents an editable form of all extracted fields with per-field confidence tints, evidence quotes/keyframes, and a pan-map-under-fixed-crosshair pin adjuster.
- [ ] Ambiguous shares show the dedupe candidate picker; selecting a candidate links to the existing place in the PATCH payload.
- [ ] Confirm submits `PATCH /shares/:id` with corrections + `action: publish`; Discard removes the share; failures surface retry.
- [ ] Component tests cover both screens (polling termination, failure-reason table, prefill, 422 mapping, payload shapes) and pass in CI.

## Verification

```bash
cd apps/mobile
npx tsc --noEmit && npx eslint . && npm test -- --testPathPattern='(status|review)'
npx expo start --dev-client
```
Manual device steps (dev client + local API): share a URL that the seeded backend routes to `review` → stepper advances → auto-lands on ExtractionReview → edit the name, pan the pin, Confirm → success screen → View on map. Then force a failure (bad URL) → correct failure copy + Retry works. Maestro: extend `share-to-publish.yaml` (deep-link entry) to assert fixture place name on the review screen and the published state after Confirm.

## Gotchas

- v5 `refetchInterval` receives the **query object** (`q.state.data`), not data directly — the v4 signature silently breaks polling termination.
- Don't rely on polling alone to see `review`: a push tap can land the user directly on `/shares/[id]/review` cold — the review screen must fetch its own share and not assume navigation came from AnalysisStatus.
- Endpoint drift: mobile spec mentions `POST /shares/:id/confirm`; the implemented API (T-024) is `PATCH /shares/:id` with `action: "publish"` — use PATCH, types from contracts will enforce it.
- Pan-under-crosshair: read coordinates from `onRegionChangeComplete` (never `onRegionChange`, which fires per frame) and keep region out of React state (§4.4 rule 5).
- `react-native-maps` on Android requires the dev-client build (Google Maps native module + API key from `app.config.ts`) — Expo Go will crash; same dev-client caveat as T-025.
- The PATCH payload must be the **merged full extraction** (server validates against the whole schema with `additionalProperties: false`) — send every field, not a diff.
- Poll interval respects battery/rate limits: 2.5 s × the 60/min default limiter is fine, but pause polling when the app backgrounds (`AppState`) to avoid burning the rate limit before the user returns.

## Log

### 2026-07-21 — Implemented (branch `feat/T-026-mobile-analysis-review`, status → in_progress)

Built the two screens + the correction form on top of the mature inline `share.tsx` compose flow rather than replacing it. All mobile gates green: `expo lint`, `tsc --noEmit`, `jest` **270 → 271** (11 new, both screens + interactive editors), new-code coverage: hooks 100%, review components ~95%, screens ~82%.

**Shipped**
- `app/shares/[id]/status.tsx` — deep-linkable AnalysisStatus: vertical stepper (pending→fetching→analyzing→review/published), terminal handling (published success + View-on-map + PendingVenues; failure card), and the failure-reason table. Auto-`router.replace`s an *editable* review to the correction form.
- `app/shares/[id]/review.tsx` — ExtractionReview correction form (loader parent + lazy-init form child, no setState-in-effect): editable name/category/cuisines/price/full-address/venue-handle, per-field confidence tints (`confidence.per_field`), evidence panel (caption/transcript quotes), pan-under-fixed-crosshair `PinAdjuster`, dedupe `CandidatePicker`, `DishEditor`, vibe/dietary chip multi-selects. Confirm → `PATCH /shares/:id {extraction(partial), place_candidate, action:'publish'}` → `router.replace` to status; Discard → `DELETE`. 422 → per-field errors via the T-010 `ValidationError` interceptor.
- Components under `src/components/share/review/` (self-contained `makeStyles`, MERCADO design): `confidence-field`, `chip-select`, `price-select`, `dish-editor`, `evidence-panel`, `candidate-picker`, `pin-adjuster`.
- Hooks `useUpdateShare` (PATCH, seeds+invalidates share/map/my-places) + `useDiscardShare` (DELETE). Narrowed `ShareDetail.analysis.extraction` from `Record<string,unknown>` → `ReelmapExtraction` (imported from `@reelmap/contracts`); added `isRetryable`/`hasEditableExtraction`/`ShareUpdatePayload`/`FailureCode` + `ExtractionPlace`.
- Wire-ups: `share.tsx` review dead-end → "Review & publish" CTA (when `hasEditableExtraction`); recent-share rows for non-published shares → `/shares/[id]/status`. Routes registered in the root Stack. i18n keys (en+es) under `shares.*` / `review.*`.

**Deviations from the spec (ADRs — spec NOT edited)**
1. **Additive, not a rewrite** — kept the inline `share.tsx` compose→publish flow (mature, auto-opens the pin on clean publish) and added the dedicated `/status` + `/review` routes as the re-entry + correction surfaces. `/status` is what My-Shares rows / push (T-027) / Maestro deep-link into; the inline flow hands off to `/review` for the correction.
2. **Real failure taxonomy** — the failure-reason table keys on the ACTUAL pipeline codes (`fetch_unavailable`, `fetch_auth_required`, `geocode_failed`, `media_too_large`, `ffmpeg_error`, `transcribe_error`, `cost_cap_exceeded`, `quota_exhausted`, `invalid_model_output`, `resolve_conflict`), **not** the spec's guessed names (`unsupported_url`/`private_post`/`not_a_restaurant`/`analysis_failed`/`quota_exceeded`), which don't exist. Unknown codes fall back to generic copy.
3. **Partial correction, not full merged payload** — earlier note said "send every field". The API (T-097 `ExtractionCorrector`) deep-merges a PARTIAL and validates the *merged* result, so the form sends only the edited leaves for `places[0]` (+ `place_candidate`). Lists (cuisines/dishes/tags) replace wholesale so they're sent in full.
4. **Untouched pin re-geocodes** — `place_candidate.lat/lng` is sent ONLY when the user actually pans the crosshair; an untouched pin is omitted so the backend re-geocodes the corrected address (better for `geocode_failed`). A picked candidate sends `place_candidate.place_id` and hides the pin adjuster.
5. **Deferred, noted for follow-up:** (a) link-account action — no mobile link screen yet (T-015 follow-up), so private posts (`fetch_auth_required`) route to "Add by hand"; (b) keyframe thumbnails in the evidence panel — the share payload carries only `frame_refs` indexes, not asset URLs, so the API must expose keyframe media first; (c) influencer handle/platform shown as read-only "posted by" context (editable field is the venue `handle`, which drives placement); (d) vibe/dietary tags rendered as canonical English enum labels (title-cased) — discovery-side i18n is server-driven.

**On merge:** flip T-026 → done in tasks.json. Device-verify: seed a `geocode_failed` review → stepper → auto-lands on the form → edit name, pan pin, Publish → status watches it publish → View on map. Then a bad-URL failure → correct failure copy + Retry.
