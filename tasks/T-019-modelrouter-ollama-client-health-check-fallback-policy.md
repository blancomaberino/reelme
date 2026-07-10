# T-019 — ModelRouter: Ollama client, health check, fallback policy

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-011
- **Target paths:** `apps/api/app/Services/AI/ModelRouter.php`, `apps/api/app/Services/AI/OllamaClient.php`
- **Spec refs:** [04-analysis-pipeline.md#model-orchestration](../04-analysis-pipeline.md) §3

## Context

The pipeline's AI stage is local-first (Ollama, zero marginal cost) with automatic remote fallback (ADR-005). This task builds the single entry point for LLM calls — `ModelRouter` — plus the `OllamaClient`, the health check, the fallback trigger policy, and per-attempt `analysis_runs` accounting (table from T-011). T-020 adds the real `OpenRouterClient` behind the same engine contract; T-021's `ExtractPlaceData` is the consumer. Only T-011 is a hard dependency — this can be built in parallel with the job chain. Application code lives in the separate app repo created by T-001 (under `apps/api/`), NOT this plans folder.

Namespace note: the spec sketch says `App\Ai\ModelRouter`; the task's target path fixes the canonical namespace as `App\Services\AI` — use that.

## Implementation steps

1. **Config** `apps/api/config/ai.php` (+ `.env.example` entries): `ollama.url` (`OLLAMA_URL`, default `http://localhost:11434`), `ollama.vision_model` (`OLLAMA_VISION_MODEL=qwen2.5-vl:7b`), `ollama.text_model` (`OLLAMA_TEXT_MODEL=qwen2.5:14b`), `ollama.timeout` (`OLLAMA_TIMEOUT=180`), `ollama.health_timeout` (2), `ollama.health_cache_seconds` (30), plus placeholders `openrouter.*` (T-020) and cost caps `max_cost_per_run` (`AI_MAX_COST_PER_RUN=0.10`), `daily_user_budget` (`AI_DAILY_USER_BUDGET=0.50`).
2. **Engine contract** (`apps/api/app/Services/AI/Contracts/AnalysisEngine.php` — the `AnalysisModel` contract named in 01 §1):
   ```php
   interface AnalysisEngine
   {
       public function name(): \App\Enums\AnalysisEngine;   // local | openrouter
       public function isHealthy(): bool;
       /** @throws EngineUnavailable | GenerationFailed */
       public function generate(GenerationRequest $r): GenerationResult;
   }
   // GenerationRequest: model, systemPrompt, userParts[] (text|image: base64+mime), jsonSchema?, temperature
   // GenerationResult: rawText, inputTokens, outputTokens, costUsd, durationMs, model
   ```
3. **`OllamaClient`** (`apps/api/app/Services/AI/OllamaClient.php`) — thin HTTP wrapper (Laravel `Http`, base URL from config):
   - `listModels(): array` → `GET /api/tags` (name, size, family) — also consumed by T-020's `GET /models`.
   - `chat(...)` → `POST /api/chat` with `messages` (images as base64 array on the user message per Ollama's API), `format: "json"` (or full schema on versions with grammar-constrained decoding), `stream: false`, `options.temperature`; request timeout `ollama.timeout`. Map response: `message.content`, `prompt_eval_count` → input tokens, `eval_count` → output tokens, `cost_usd = 0` for local.
   - `healthy(): bool` → `GET /api/tags` with the 2 s health timeout, result cached 30 s (`Cache::remember('ollama:healthy', 30, ...)`). Connection errors → false, never throw.
   - `LocalEngine` implements `AnalysisEngine` wrapping the client (model choice: vision model when request has images, text model otherwise).
4. **`ModelRouter`** (`apps/api/app/Services/AI/ModelRouter.php`) — routing algorithm per 04 §3, exposed as `route(Share $share, GenerationRequest $request, ?string $preferredModel = null): AnalysisRun`:
   1. Resolve preference: `preferredModel ?? $share->user->preferred_analysis_model ?? 'auto'` (column name per 02 §3.1 — the spec's `ai_model_pref` alias refers to this). A pinned OpenRouter model skips local and goes straight to remote with it.
   2. `auto`: Ollama health check; unreachable → fallback with reason `ollama_unreachable`.
   3. Local attempt via `LocalEngine`.
   4. **Fallback triggers** (each records the reason): health check failed (`ollama_unreachable`); generation error/timeout (`ollama_error`); caller-reported invalid JSON after the 2-attempt repair loop (`invalid_json` — the repair loop itself lives in T-021, so `route()` accepts a `validate: fn(string $raw): ValidationOutcome` callback the router invokes per attempt); parsed `confidence.overall < 0.5` (`low_confidence`).
   5. Remote attempt via the `AnalysisEngine` bound as `'openrouter'` — in this task a `NullRemoteEngine` stub that throws `EngineUnavailable` (T-020 swaps in the real client; the binding seam is the deliverable).
   - **Every attempt — including failures — writes an `analysis_runs` row** (T-011 schema): `engine`, `model`, `status` (`running` → `succeeded|failed`), `started_at`/`finished_at`, `input_tokens`, `output_tokens`, `cost_usd` (0 for local), `overall_confidence`, `result_json` (only when schema-valid), `error` (exception message or fallback reason — the schema has no `fallback_reason` column, so encode it here as `fallback:{reason}` prefix). Duration derives from the timestamps.
   - Both engines dead → throw `AllEnginesFailed` (caller maps to failure taxonomy `ollama_unreachable`/`invalid_model_output`).
5. **Service provider** (`AiServiceProvider`): singleton bindings for `OllamaClient`, `LocalEngine`, `'openrouter' => NullRemoteEngine`, `ModelRouter`.
6. **Unit tests** (`tests/Unit/Services/AI/`, all `Http::fake`, no live Ollama):
   - `OllamaClient::listModels` parses a captured `/api/tags` fixture; `chat` sends base64 images + `format: json` (assert via `Http::assertSent`) and maps token counts.
   - Health check: 200 → healthy; connection exception → false; result cached (second call sends no HTTP — assert request count).
   - Router matrix: healthy + valid JSON + confidence 0.9 → one `analysis_runs` row (`local`, `succeeded`, cost 0); unreachable → local skipped, remote attempted, run rows record reasons; validate-callback failing twice → fallback with `invalid_json`; confidence 0.4 → fallback `low_confidence`; user-pinned model → no local attempt; remote stub failing → `AllEnginesFailed` and every attempt has a persisted run row (assert row count + statuses).

## Acceptance criteria

- [ ] `OllamaClient` supports configurable `OLLAMA_URL`, `generate/chat` with base64 images and `format: json`, and `listModels()` from `/api/tags`.
- [ ] Health check uses a 2 s timeout, caches for 30 s, and never throws.
- [ ] Fallback fires on exactly the spec triggers: unreachable/health-fail, generation error or timeout, schema-invalid output after 2 repair attempts (via the validate callback), and `confidence.overall < 0.5`.
- [ ] Every attempt (success and failure, local and remote) is recorded as an `analysis_runs` row with engine, model, status, token counts, `cost_usd` (0 for local), confidence, timestamps, and error/fallback reason.
- [ ] User-pinned model (`users.preferred_analysis_model`) routes directly to the remote engine; `auto` is local-first.
- [ ] Remote engine is a swappable container binding (`NullRemoteEngine` now, T-020's OpenRouterClient later) — no ModelRouter changes needed in T-020.
- [ ] Unit tests with fake HTTP cover the full routing matrix; no network in CI.
- [ ] `pint --test` and `phpstan analyse` pass.

## Verification

```bash
cd apps/api
php artisan test --filter=ModelRouter
php artisan test --filter=OllamaClient
# local smoke with Ollama running (ollama serve + a pulled vision model):
php artisan tinker --execute="
  \$c = app(\App\Services\AI\OllamaClient::class);
  var_dump(\$c->healthy());
  print_r(array_column(\$c->listModels(), 'name'));
"
# expect: bool(true) and your local model tags; with Ollama stopped: bool(false) (after 30s cache expiry)
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

## Gotchas

- **`analysis_runs` has no `fallback_reason`/`duration_ms` columns** (02 §3.7 is canonical even though 04 §3 mentions both) — encode the fallback reason in `error` and derive duration from `started_at`/`finished_at`. Don't add columns without an ADR.
- Health-check caching cuts both ways: a 30 s stale "healthy" can send one doomed request after Ollama dies — that's fine (it becomes an `ollama_error` fallback), but never cache longer, and bust the cache on connection failure.
- Ollama timeouts: distinguish the 2 s **health** timeout from the 180 s **generation** timeout; `Http::timeout()` is per-request. Also set `connectTimeout` (~5 s) on generation so a dead host doesn't eat 180 s.
- Token counts: Ollama's `prompt_eval_count` is absent when the prompt was cached — default to 0/null, don't crash.
- The router must be **queue-safe**: no state between calls; the enclosing job (T-021, `analysis` queue) provides retries — router-internal retries are limited to the engine-fallback step, never loops.
- Base64-encoding 12 JPEG keyframes ≈ several MB per request — build `GenerationRequest` lazily from storage paths and encode at send time; never persist base64 into `analysis_runs.result_json` or logs.
- `result_json` must only be persisted when it validated against the contracts schema (02 §3.7 note) — store raw invalid output nowhere except truncated in `error` (cap ~2 KB) for debugging.
- Keep `NullRemoteEngine` honest: it must *throw* `EngineUnavailable`, not fake success, so pre-T-020 integration tests exercise the real failure path.
