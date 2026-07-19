# T-079 — Card-specific discounts: extract from captions, surface on place, filter by card

- **Phase:** M2 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-021 (extraction job), T-023 (resolve/dedup), T-030 (places API), T-033 (mobile place detail), T-032 (mobile map + FilterBar)
- **Target paths:** `packages/contracts/extraction.schema.json` (+ generated TS), `apps/api/resources/prompts/extraction.system.md`, `apps/api/app/Models/Place.php`, `apps/api/app/Http/Resources/PlaceResource.php`, `apps/api/app/Http/Controllers/Api/V1/` (map + places index), `apps/mobile/app/place/[slug].tsx`, `apps/mobile/src/components/map/filter-bar.tsx`, `apps/mobile/src/stores/map.ts`, `apps/api/public/demo.html`
- **Spec refs:** `04-analysis-pipeline.md`, `03-api-design.md#places`, `05-mobile-app.md`

## Context

Requested 2026-07-17. Captions frequently advertise card/bank discounts — e.g.
"20% con Visa", "3 cuotas sin interés con Santander", "descuento pagando con
Mercado Pago". When the caption states one, capture it and show it on the place,
and let users **filter by card** ("show me places with a Santander discount").

**This is informational, caption-derived, and unverified** — distinct from the
M4 merchant `offers` program (T-042), which is owner-published and redeemable.
Do not conflate the two: no redemption, no quota, no ledger here.

### Grounding (already in the codebase — don't re-invent)

- **Extraction schema** `packages/contracts/extraction.schema.json` (draft-07,
  `ReelmapExtraction`). Per-place fields today: `name, handle, category, cuisines,
  address, geo, price_range, phone, website, opening_hours_text, dishes, vibe_tags,
  dietary_tags, confidence`. **No discount field yet.** `dishes[]` is the closest
  precedent: `{name, shown_in_video, price}` with the currency symbol kept verbatim.
- **Prompt** `apps/api/resources/prompts/extraction.system.md` — current
  `<!-- prompt-version: extraction.v9 -->`. Bump to **v10**.
- **TS gen + drift**: `packages/contracts/scripts/generate.ts`
  (`npm run generate -w packages/contracts`) → `packages/contracts/src/generated/*`;
  drift test `packages/contracts/tests/extraction.schema.test.ts`. Keep green.
- **Dishes/tags are NOT columns** — `Place::aggregatedTags()` unions them
  read-time from each `place_source.extraction_snapshot_json` (dishes deduped by
  name, first wins). **Model card discounts the same way** (per-source, aggregated),
  so a multi-source place unions every mentioned discount; no `places` column.
- **Mobile filters**: Zustand store `apps/mobile/src/stores/map.ts`, shape
  `MapFilters { cuisine, price_range, tags[], list, filter }`, UI
  `src/components/map/filter-bar.tsx`. The card facet slots in here.
- **Place detail** renders merged tag chips + `MenuSheet`; a discounts block fits
  beside them. Web parity in `apps/api/public/demo.html` (`esc()`-guarded).

## Implementation

- **Schema:** add `places[].discounts[]` — objects like
  `{ card (e.g. "Visa" / "Santander" / "Mercado Pago"), terms (verbatim caption
  phrasing, e.g. "20% off"), percent (int|null) }`, small `maxItems`. Regenerate TS.
- **Prompt v10:** fill `discounts` **only** from explicit caption/transcript text
  naming a bank/card/payment method + a benefit. Never infer or borrow across
  venues (same guardrail spirit as prompt v9's price rules). Absent → `[]`.
- **Aggregation:** extend `Place::aggregatedTags()` (or a sibling accessor) to
  union `discounts` across sources, deduped by normalized card name.
- **API:** `PlaceResource` emits `discounts`; the places index + map bbox
  endpoints accept a card filter (e.g. `?card=santander`) matching places whose
  aggregated discounts include that card.
- **Mobile:** discounts chips on place detail; `MapFilters` gains a card facet +
  store action; `FilterBar` surfaces available cards (popular-cards style).
- **Web demo:** render the discounts block in the detail drawer, `esc()`-guarded.

## Acceptance criteria

- [ ] `extraction.schema.json` gains `places[].discounts`; prompt **v10** fills it
      only from explicit caption text (no invention/borrowing); TS regen + drift green
- [ ] Discounts aggregated read-time from source snapshots (not a `places` column);
      `PlaceResource` emits them
- [ ] Place detail shows a discounts block/chips (card + terms) on mobile + web,
      localized es/en, XSS-guarded
- [ ] Map + my-places can filter to places offering a given card; index/map
      endpoints honor the filter
- [ ] Tests: fixture with card discounts populates the field; a no-discount caption
      yields `[]` (no invention); API card filter returns only matching places;
      mobile jest/tsc; API Pint/PHPStan L6/Pest green

## Verification

- Contracts: `npm test -w packages/contracts` (schema + drift), `npx tsc --noEmit`.
- API: extraction fixture → snapshot carries discounts; `GET /places?card=…` filters; Pest/Pint/PHPStan.
- Mobile: a place with discounts shows chips; selecting a card filters the map; `tsc`/`lint`/`jest`.
- Web: demo drawer shows the discounts block; guards intact.
