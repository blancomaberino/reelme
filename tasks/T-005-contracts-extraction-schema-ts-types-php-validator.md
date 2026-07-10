# T-005 — packages/contracts: extraction JSON schema + generated TS types + PHP validator

- **Phase:** M0 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-001
- **Target paths:** `packages/contracts/`
- **Spec refs:** [04-analysis-pipeline.md#extraction-json-schema](../04-analysis-pipeline.md#extraction-json-schema)

## Context
T-001 created the monorepo (separate app repo — not this plans folder) with an empty `packages/contracts/` workspace. Per ADR-006 this package is the single source of truth for the AI extraction contract shared by the Laravel API (validation of `analysis_runs.result_json`, T-021) and the mobile app (typed review UI). This task ships the canonical `extraction.schema.json`, TS type generation, a PHP validation helper, and a round-trip test proving both sides accept the same payload.

## Implementation steps
1. Create `packages/contracts/extraction.schema.json` containing **exactly** the JSON Schema block from 04-analysis-pipeline §4 (`$id: https://reelmap.app/contracts/extraction.schema.json`, draft-07, `title: ReelmapExtraction`). Copy verbatim — every `required` array, `additionalProperties: false`, nullable enums including the trailing `null` members, and min/max bounds. This exact path is load-bearing (referenced by ROADMAP exit criteria and T-021). Also create `schemas/` dir for future per-resource schemas (01-architecture §6) with a README pointing at the canonical extraction file.
2. Package layout:
   ```
   packages/contracts/
     extraction.schema.json
     schemas/README.md
     src/generated/extraction.ts     # generated — committed
     src/index.ts                    # re-exports generated types
     scripts/generate.ts
     examples/valid-extraction.json  # shared fixture
     examples/invalid-extraction.json
     tests/extraction.schema.test.ts
     package.json  tsconfig.json  jest.config.js
   ```
3. `package.json` (name `@reelmap/contracts`, workspace member). Dev deps, resolving latest stable at install time: `json-schema-to-typescript`, `ajv` + `ajv-formats` (draft-07 validation in tests), `typescript`, `tsx`, `jest`, `ts-jest` (or vitest — pick one; T-006 runs `npm test -w packages/contracts`). Scripts:
   - `"generate": "tsx scripts/generate.ts"`
   - `"test": "jest"`, `"typecheck": "tsc --noEmit"`
4. `scripts/generate.ts`: read `extraction.schema.json`, run `compile()` from json-schema-to-typescript (`additionalProperties: false` honored, banner comment "GENERATED — do not edit; run npm run generate"), write `src/generated/extraction.ts` exporting `ReelmapExtraction`. `src/index.ts` re-exports it.
5. Fixtures: `examples/valid-extraction.json` — a realistic full payload (e.g. the Lanzhou Noodle House example from 03-api-design §3.2 expanded to full schema shape: `place` with all required keys incl. nulls, `influencer`, `post`, `evidence` with `frame_refs: [0,2]`, `confidence.overall: 0.91` + `per_field`). `examples/invalid-extraction.json` — e.g. missing `confidence`, an extra top-level property, `frame_refs: [12]` (out of bounds).
6. TS-side test (`tests/extraction.schema.test.ts`): Ajv (draft-07) compiles the schema; valid fixture passes; invalid fixture fails with expected error paths; type-level check that the valid fixture assigns to `ReelmapExtraction` (compile-time via `satisfies`/typed import).
7. Drift guard test: regenerate types in-memory and diff against the committed `src/generated/extraction.ts` — fail with "run npm run generate" message (this is what CI relies on in T-006).
8. **PHP-side helper** in the API app (this task touches `apps/api` minimally): `composer require opis/json-schema` (latest stable). Create `apps/api/app/Support/Contracts/ExtractionSchema.php`:
   ```php
   final class ExtractionSchema
   {
       public static function path(): string
       { return base_path('../../packages/contracts/extraction.schema.json'); }

       /** @return \Opis\JsonSchema\ValidationResult */
       public static function validate(object|array $payload): ValidationResult { /* opis Validator */ }
   }
   ```
   Resolve the schema file relative to the repo root (config value `contracts.extraction_schema_path` with the monorepo-relative default) so deploys can override.
9. **Round-trip Pest test** (`apps/api/tests/Unit/ExtractionSchemaTest.php`): load `packages/contracts/examples/valid-extraction.json` → validates OK via `ExtractionSchema::validate()`; invalid fixture → fails with errors. Same fixtures the TS test uses — that is the round-trip guarantee.
10. Root README of the package: how to change the schema (edit json → `npm run generate` → commit both → mirror into 04-analysis-pipeline.md per the spec's golden rule).

## Acceptance criteria
- [ ] `packages/contracts/extraction.schema.json` is byte-equivalent in structure to the schema in 04-analysis-pipeline §4 (same `$id`, draft-07, all required/enum/bounds/`additionalProperties: false` constraints — verifiable by JSON-normalized diff against the spec block).
- [ ] `npm run generate -w packages/contracts` produces committed `src/generated/extraction.ts` exporting a `ReelmapExtraction` type via json-schema-to-typescript.
- [ ] A drift test fails when the schema and generated types are out of sync.
- [ ] PHP validation helper (opis/json-schema) exists in `apps/api` and is usable as `ExtractionSchema::validate($payload)`.
- [ ] Round-trip: the SAME `examples/valid-extraction.json` validates in TS (Ajv test) and in PHP (Pest test); the same invalid fixture fails in both.
- [ ] Package tests + typecheck green; Pest suite in `apps/api` still green.

## Verification
```bash
# repo root
npm install
npm run generate -w packages/contracts && git diff --exit-code packages/contracts/src/generated
npm test -w packages/contracts
npm run typecheck -w packages/contracts

cd apps/api && composer test -- --filter=ExtractionSchema
```

## Gotchas
- The schema is **draft-07** — use Ajv's draft-07 mode (default `new Ajv()`, not `Ajv2020`) and opis/json-schema handles 07 natively; mismatched drafts silently change `enum`-with-null and `format` semantics.
- `"format": "uri"` on `place.website`: Ajv needs `ajv-formats`; opis validates formats only if enabled — either enable on both sides or treat format as annotation on both. Pick ONE behavior and assert it in both tests, otherwise round-trip parity is a lie.
- Nullable enums like `"enum": [..., null]` combined with `"type": ["string","null"]` are valid draft-07 but json-schema-to-typescript can emit clumsy unions — verify the generated type includes `null`, don't hand-edit generated output.
- The PHP helper reads a file OUTSIDE the Laravel app root (`../../packages/...`); `base_path()` relative traversal works locally but confirm the path also resolves in CI (checkout is the whole monorepo) and make it configurable for production deploys that ship only `apps/api`.
- Keep generated TS **committed** — the mobile app and CI drift-check depend on it; add the "GENERATED" banner so reviewers don't edit it.
- Any future schema change must update the mirrored block in `04-analysis-pipeline.md` (spec's golden rule) — note this in the package README.

## Log
- **2026-07-09** — Done. **PR #2** (`feat/t005-contracts` → main), first of the stacked M0 backend PRs. Contracts: `npm test` 5 passing (valid/invalid fixtures, error-path assertions, type-level check, drift guard), `typecheck` clean, `generate` → no drift. API (Sail/Postgres): `composer test` 20 passing / 60 assertions incl. 4 round-trip tests, Pint + PHPStan L6 clean.
- **Deviations / notes**:
  - Schema copied verbatim from spec §4; `frame_refs` max is 11.
  - **Round-trip fixtures shared** by TS (Ajv + `ajv-formats`) and PHP (opis/json-schema); formats enabled on both sides for parity.
  - **Monorepo path resolution**: `config/contracts.php` defaults to `base_path('../../packages/contracts/...')` (works on host + CI full checkout). The Sail container mounts `packages/contracts` read-only at `/srv/contracts` and sets `CONTRACTS_EXTRACTION_SCHEMA_PATH`/`CONTRACTS_EXAMPLES_PATH` explicitly (cleaner than base_path arithmetic — surfaced by /simplify altitude review).
  - **Drift test** runs `generate` in a subprocess (`execFileSync npx tsx`, `CONTRACTS_OUT` temp file) because json-schema-to-typescript's Prettier v3 ESM formatter can't load in Jest's CommonJS VM. The `npm run generate && git diff --exit-code` check is the CI-facing equivalent.
  - **/simplify** applied: memoized schema+Validator in ExtractionSchema, object-payload normalize short-circuit, dedup test loader, hoisted Ajv compile.
  - **/security-review** clean. Deferred hardening for **T-021** (when validate() is HTTP-facing): add `maxItems`/`maxLength` caps (needs an ADR since schema is spec-locked) + wrap normalize's `JsonException`.
  - `pest-plugin-laravel` still omitted (L13); unrelated.
