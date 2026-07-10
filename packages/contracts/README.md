# @reelmap/contracts

Shared JSON Schemas + generated TypeScript types — the **single source of truth**
for Reelmap's cross-cutting data shapes (ADR-006). The Laravel API validates
against these schemas; the Expo app generates its types from them.

## Canonical files

- **`extraction.schema.json`** — the AI extraction contract (draft-07). Mirrored
  in `04-analysis-pipeline.md §4`; validated server-side via `opis/json-schema`
  (T-021) and client-side via the generated type.
- `schemas/` — per-resource schemas as they are added.
- `examples/` — fixtures shared by the TS **and** PHP tests (the round-trip guarantee).

## Changing a schema

1. Edit the JSON schema (e.g. `extraction.schema.json`).
2. `npm run generate -w packages/contracts` — regenerates `src/generated/*` (committed).
3. Commit **both** the schema and the regenerated types.
4. **Mirror the change into the spec** (`04-analysis-pipeline.md §4` for extraction) —
   the plan's golden rule: schema changes land in `packages/contracts` first, then the doc.

A drift test fails CI if `src/generated` is out of sync with the schema.

## Scripts

```bash
npm run generate   # regenerate TS types from the schemas
npm test           # jest: Ajv validation + drift guard
npm run typecheck  # tsc --noEmit
```

## Format validation

`place.website` uses `"format": "uri"`. Formats are **enabled on both sides**
(Ajv via `ajv-formats`; opis/json-schema natively) so validation parity holds —
do not disable formats on one side only.
