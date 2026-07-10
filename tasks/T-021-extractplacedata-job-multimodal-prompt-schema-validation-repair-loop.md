# T-021 — ExtractPlaceData job: multimodal prompt, schema validation, repair loop

- **Phase:** M1 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-018, T-019, T-020, T-005
- **Target paths:** `apps/api/app/Jobs/ExtractPlaceData.php`, `apps/api/app/Services/AI/Prompts/`
- **Spec refs:** [04-analysis-pipeline.md#prompting-strategy](../04-analysis-pipeline.md#5-prompting-strategy), [04-analysis-pipeline.md#extraction-json-schema](../04-analysis-pipeline.md#4-extraction-json-schema-canonical-contract)

## Context

At this point the repo has the full media path (T-017 keyframes/audio, T-018 transcript on `source_posts.transcript_json`), the `ModelRouter` with Ollama + OpenRouter engines and cost caps (T-019/T-020), and the canonical `packages/contracts/extraction.schema.json` with a PHP validator helper (T-005). This task builds the pipeline's brain: the `analyze`-queue job that assembles the multimodal prompt, drives the router, enforces the schema with a repair loop, and gates the share toward `review` or `ResolvePlace`. It unlocks T-023 (ResolvePlace) and the review flow (T-024). App code lives in the separate app repo created by T-001, NOT this plans folder.

## Implementation steps

1. **Prompt assets** under `apps/api/resources/prompts/` + builder classes in `apps/api/app/Services/AI/Prompts/`:
   - `extraction.system.md` — the system prompt verbatim from 04 §5 (never-invent rules, evidence quoting, geo-only-from-explicit-coordinates, `shown_in_video` rule, English `caption_summary`, honest confidence). Embed a version string (e.g. `v1`) recorded on each run as `analysis_runs.prompt_version` (add the column if T-011 didn't).
   - `ExtractionPromptBuilder::build(Share $share): PromptPayload` assembling the user message parts in this exact order:
     ```text
     [for each keyframe i in index order]
       text: "frame {i} @ {mm:ss}"        // from media_assets.frame_at_ms
       image: keyframe bytes (base64)
     text: "CAPTION:\n{source_post.caption}"
     text: "TRANSCRIPT:\n{segments with [start–end] timestamps}"
     text: full extraction.schema.json inline
     text: "Respond with a single JSON object valid against the schema above."
     ```
   - No usable keyframes → text-only variant (router may use `OLLAMA_TEXT_MODEL`).
2. **`ExtractPlaceData` job** (`queue: analyze`, tries 3, backoff `[30, 180, 600]`, timeout 600s) following the shared stage contract from 04 §1: assert `shares.status === analyzing` (transition `fetching → analyzing` on entry if that's this stage's responsibility per T-016's state machine); idempotency — if a succeeded `analysis_runs` row already exists for this share, skip straight to the gate in step 5.
3. **Engine execution via `ModelRouter`** — the job calls the router once per execution; the router owns local-vs-remote choice, fallback triggers, and `analysis_runs` bookkeeping. Job-level retries are transport retries only.
4. **Parse → validate → repair loop** (max 2 repair attempts inside one job execution, per engine attempt):
   1. Tolerant extraction: strip ```json fences/leading prose, take the first balanced `{…}` block.
   2. Validate with the T-005 helper (`opis/json-schema`) against `packages/contracts/extraction.schema.json`.
   3. Failure → repair attempt 1: re-send the same conversation + the raw model output + `Your previous output failed validation: {validator errors}. Return the corrected JSON object only.`
   4. Still failing → repair attempt 2, same shape, temperature 0.
   5. Still failing → dead engine attempt: local → router falls back to OpenRouter; OpenRouter → job failure `invalid_model_output` (final failure ⇒ share `failed`).
5. **Persist + confidence gate** on the winning run: store `result_json` + `overall_confidence` on the `analysis_runs` row, link `shares.analysis_run_id`. Then:
   - `place.name === null` (valid payload, no place) → share `review`, `review_reason: no_place_extracted`.
   - `confidence.overall < 0.75` → share `review` (push notification fires via `ShareStatusChanged`).
   - Otherwise → let the chain continue to `ResolvePlace`.
   - (The `< 0.5` gate is a **router fallback trigger** — local result under 0.5 escalates to OpenRouter before this gate is evaluated.)
6. **`failed()` hook**: share → `failed`, `failure_code: invalid_model_output` (or taxonomy code from the thrown exception), push notification.
7. **Golden-file tests** (Pest): fixtures directory with keyframe stubs, caption, transcript; `Http::fake()` (or a `FakeModelRouter`) returning canned model outputs. Cover: clean valid output → gate passes; fenced/prose-wrapped output → tolerant parse; invalid → repaired on attempt 1; invalid ×3 locally → OpenRouter fallback recorded; `overall < 0.75` → `review`; `place.name: null` → `review` + `no_place_extracted`; both-engine failure → share `failed`. Assert `analysis_runs` rows per attempt (engine, status, fallback reason, cost).

## Acceptance criteria

- [ ] Prompt assembly matches 04 §5 exactly: numbered `frame {i} @ {mm:ss}` text before each image, CAPTION block, TRANSCRIPT block with segment timestamps, inline schema, final instruction; system prompt versioned and version recorded on the run.
- [ ] Every model output is validated against `packages/contracts/extraction.schema.json` via the T-005 PHP helper; a 2-attempt repair loop runs inside a single job execution before an engine attempt is declared dead.
- [ ] Local dead-end triggers OpenRouter fallback through `ModelRouter`; OpenRouter dead-end fails the job with `invalid_model_output`.
- [ ] Winning run persists `result_json` + `overall_confidence`; share routes to `review` when `overall < 0.75` or `place.name` is null (`review_reason: no_place_extracted`), otherwise continues to `ResolvePlace`.
- [ ] Golden-file tests drive fixture inputs through a fake model to valid schema output, covering happy, repair, fallback, review-gate, and failure paths — no network.

## Verification

```bash
cd apps/api
php artisan test --filter=ExtractPlaceData
php artisan test tests/Feature   # nothing else regressed
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Expected: green; test output shows `analysis_runs` assertions for local+fallback scenarios. Optional live smoke with Ollama running: dispatch the job for a seeded share and inspect `analysis_runs.result_json`.

## Gotchas

- **JSON repair loops must be bounded and non-recursive** — exactly 2 repair attempts per engine attempt, inside one job execution. Don't let Horizon retries multiply into repair×retry×fallback explosions; job retries are for transport errors only.
- The tolerant extractor must handle: markdown fences, `Here is the JSON:` prefixes, trailing commentary, and models echoing the schema itself. Take the **first balanced** `{…}` block, not a greedy regex.
- Schema is `additionalProperties: false` everywhere and all `required` arrays include nullable fields — models frequently omit `null` fields entirely, which **fails validation**. That's what the repair loop is for; don't "fix" it by relaxing the schema.
- A payload that validates but has `place.name = null` is *not* a failure — it's `review` / `no_place_extracted`. Don't route it into the repair loop.
- Keyframe indexes in `evidence.frame_refs` are the prompt-order indexes (0..11, matching `frame_{index}_{ms}.jpg` from T-017) — keep prompt order identical to asset index order or evidence refs become meaningless.
- Base64-ing 12 JPEGs per attempt is memory-heavy; stream from storage and build parts lazily, and never log the full prompt payload.
- Ollama `format: "json"` guarantees syntactic JSON only — schema validation is still mandatory (and newer Ollama grammar-constrained `format: <schema>` is optional support, not assumed).
