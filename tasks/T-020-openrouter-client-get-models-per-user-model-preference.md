# T-020 — OpenRouter client + GET /models + per-user model preference

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-019
- **Target paths:** `apps/api/app/Services/AI/OpenRouterClient.php`, `apps/api/app/Http/Controllers/ModelController.php`
- **Spec refs:** [04-analysis-pipeline.md#model-orchestration](../04-analysis-pipeline.md#3-model-orchestration--local-first-remote-fallback), [03-api-design.md#analysis](../03-api-design.md#25-analysis)

## Context

T-019 delivered `App\Services\AI\ModelRouter` with an `OllamaClient`, health check, and the fallback-trigger policy, recording every attempt as an `analysis_runs` row. This task adds the remote half: an OpenRouter chat-completions client the router falls back to, the merged model catalog endpoint the mobile model picker consumes, the per-user model preference, and the cost guardrails. It unlocks T-021 (`ExtractPlaceData`), which needs a working remote path. App code lives in the separate app repo created by T-001 (monorepo, `apps/api`), NOT this plans folder.

## Implementation steps

1. **`OpenRouterClient`** (`apps/api/app/Services/AI/OpenRouterClient.php`), using Laravel's `Http` client against `https://openrouter.ai/api/v1`:
   - `chat(string $model, array $messages, array $options = []): OpenRouterResult` → `POST /chat/completions` with `Authorization: Bearer {OPENROUTER_API_KEY}`, plus `HTTP-Referer`/`X-Title` headers (OpenRouter attribution convention).
   - Multimodal messages: keyframes as `{"type": "image_url", "image_url": {"url": "data:image/jpeg;base64,<...>"}}` content parts — OpenRouter takes base64 **data URIs**, not raw base64 or S3 URLs (keyframe R2 URLs are private).
   - Structured output: pass `response_format: {"type": "json_schema", "json_schema": {"name": "ReelmapExtraction", "schema": <extraction schema>, "strict": true}}` when the chosen model supports it (flag per curated model in config); else `{"type": "json_object"}` and rely on schema-in-prompt. Callers still validate output regardless (spec: provider strict modes are best-effort).
   - Cost extraction: send `"usage": {"include": true}` in the request body so the response `usage` object includes `cost` (USD) alongside `prompt_tokens`/`completion_tokens`. Map to the result DTO: `prompt_tokens`, `completion_tokens`, `cost_usd`.
   - Timeout/retry: request timeout from `config('ai.openrouter.timeout')` (default 180s); no internal retries (the job layer owns retries).
2. **Curated model config** in `apps/api/config/ai.php` under `openrouter.curated_models`: array of `{id, display_name, supports_json_schema, price_prompt_per_mtok, price_completion_per_mtok, cost_class}` for vision-capable, JSON-reliable models (e.g. `anthropic/claude-sonnet-*`, `google/gemini-*-flash`, `openai/gpt-*o-mini`, `qwen/qwen2.5-vl-72b-instruct`). Also `openrouter.default_model` (`OPENROUTER_DEFAULT_MODEL` env), `ai.max_cost_per_run` (`AI_MAX_COST_PER_RUN`, default `0.10`), `ai.daily_user_budget` (`AI_DAILY_USER_BUDGET`, default `0.50`).
3. **`ModelController`** (`App\Http\Controllers\Api\V1\ModelController`) serving `GET /api/v1/analysis/models` (authed; this is the endpoint tasks refer to as "GET /models"):
   - Local entries: live `GET {OLLAMA_URL}/api/tags` via `OllamaClient`, filtered to models tagged vision-capable in config; `engine: local`, `cost_class: free`. Ollama down → local section empty, no error.
   - Remote entries: the curated list with `source`, `cost_class`, pricing.
   - First entry always `{"id": "auto", "default": true}` (recommended). Response in the standard `{data, meta}` envelope.
4. **Preference endpoint** `PUT /api/v1/me/analysis-preference` `{model: "auto"|<model id>}` — validates the id against `auto` + live local tags + curated list, writes `users.preferred_analysis_model` (column exists from the M0 users migration per 02-data-model §3.1). Reject unknown ids with 422.
5. **Wire into `ModelRouter`** (extend T-019's class):
   - Resolve `users.preferred_analysis_model` (default `auto`). A pinned OpenRouter model skips Ollama entirely; `auto` keeps local-first with the T-019 fallback triggers.
   - **Per-run cost cap**: estimate cost (prompt size × model price from config) before the remote call; if > `AI_MAX_COST_PER_RUN`, downgrade to the cheapest curated model; if still over, fail the run with `cost_cap_exceeded`.
   - **Per-user daily quota**: Redis counter keyed `ai:spend:{user_id}:{Ymd}` incremented by `cost_usd` after each run (nightly reconcile against `analysis_runs` can be a TODO stub command). Over `AI_DAILY_USER_BUDGET` → local-only; if local unavailable too, park the share in `review` with `review_reason: quota_exhausted`.
   - Every OpenRouter attempt (success or failure) writes an `analysis_runs` row: `engine: openrouter`, `model`, `status`, `input_tokens`, `output_tokens`, `cost_usd`, `error`.
6. **Routes** in `apps/api/routes/api.php` under the `v1` + `auth:sanctum` group.
7. **Tests** (Pest, `Http::fake()` — no live calls): client happy path with images + cost parsing; json_schema vs json_object selection; models endpoint merging (Ollama up and down); preference validation + persistence; cost-cap downgrade and `cost_cap_exceeded`; daily-quota → local-only → `quota_exhausted` review.

## Acceptance criteria

- [ ] `OpenRouterClient::chat()` sends multimodal messages with base64 data-URI image parts and returns parsed content + `prompt_tokens`, `completion_tokens`, `cost_usd` extracted from the response `usage` (with `usage.include: true` requested).
- [ ] `GET /api/v1/analysis/models` returns merged catalog: `auto` first (flagged default), live Ollama vision models (`engine: local`, cost 0), curated OpenRouter models with pricing/`cost_class`; degrades gracefully when Ollama is unreachable.
- [ ] `PUT /api/v1/me/analysis-preference` persists `users.preferred_analysis_model`; `ModelRouter` respects it (pinned remote model bypasses Ollama; `auto` = local-first).
- [ ] Per-run estimated cost above `AI_MAX_COST_PER_RUN` downgrades to the cheapest curated model, and fails with `cost_cap_exceeded` if still over.
- [ ] Per-user daily spend above `AI_DAILY_USER_BUDGET` forces local-only; with local unavailable the share parks in `review` with `review_reason: quota_exhausted`.
- [ ] Every OpenRouter attempt, including failures, is recorded as an `analysis_runs` row with engine/model/tokens/cost.

## Verification

```bash
cd apps/api
php artisan test --filter=OpenRouter
php artisan test --filter=Model      # models endpoint + preference tests
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Expected: all green, no live HTTP (assert via `Http::preventStrayRequests()` in tests). Manual smoke (optional, needs `OPENROUTER_API_KEY`): `php artisan tinker` → call `OpenRouterClient::chat()` with a tiny prompt against a cheap model and confirm `cost_usd > 0`.

## Gotchas

- **OpenRouter cost is not in the default response.** You must send `"usage": {"include": true}` to get `usage.cost` inline; otherwise cost is only available via a follow-up `GET /api/v1/generation?id=<gen id>` (eventually consistent — avoid; use the inline flag).
- Images must be **data URIs** (`data:image/jpeg;base64,...`); remote URLs would require public access to R2 keyframes, which we don't have. Watch payload size: 12 keyframes at 1024px ≈ several MB of JSON — raise the HTTP client body limits and don't log full request bodies.
- Not every curated model honors `json_schema` strict mode; OpenRouter silently degrades or errors depending on provider. Gate via the per-model `supports_json_schema` flag and always schema-validate downstream.
- Naming drift in the specs: 04-analysis-pipeline calls the column `users.ai_model_pref`; the data model (canonical) says `preferred_analysis_model` — use `preferred_analysis_model`. The mobile spec calls the endpoint `GET /models` / `PATCH /me`; the API spec (canonical) is `GET /analysis/models` + `PUT /me/analysis-preference` — implement the API-spec paths.
- The Redis daily counter must add per-run cost atomically (`INCRBYFLOAT`) with a TTL past midnight UTC; day boundary is UTC per spec ("auto-retries after midnight UTC").
