<?php

namespace App\Services\AI;

use App\Enums\AnalysisEngine as AnalysisEngineEnum;
use App\Enums\AnalysisStatus;
use App\Models\AnalysisRun;
use App\Models\Share;
use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\Data\GenerationRequest;
use App\Services\AI\Data\ValidationOutcome;
use App\Services\AI\Exceptions\AllEnginesFailed;
use App\Services\AI\Exceptions\CostCapExceeded;
use App\Services\AI\Exceptions\EngineUnavailable;
use App\Services\AI\Exceptions\GenerationFailed;
use App\Services\AI\Exceptions\QuotaExhausted;
use Closure;
use Illuminate\Support\Str;

/**
 * The single entry point for extraction LLM calls (04 §3). Local-first with
 * automatic remote fallback; every attempt — success or failure — is persisted
 * as an `analysis_runs` row so all spend and outcomes are queryable without a
 * separate ledger. Stateless and queue-safe: the enclosing job owns retries.
 *
 * Guardrails (T-020): a per-run cost cap downgrades to the cheapest curated model
 * (or fails `cost_cap_exceeded`), and a per-user daily budget forces local-only
 * once exhausted (parking the share via `QuotaExhausted` when local can't serve).
 */
class ModelRouter
{
    // Conservative token estimates for the pre-flight cost cap. The real cost is
    // taken from the provider's usage object after the call.
    private const IMAGE_TOKENS = 800;

    private const COMPLETION_TOKENS = 1500;

    public function __construct(
        private readonly LocalEngine $local,
        private readonly AnalysisEngine $remote,
        private readonly CuratedModels $curated,
        private readonly SpendTracker $spend,
    ) {}

    /**
     * Route a generation request for a share and return the winning (succeeded)
     * run. `$validate` receives each engine's raw output plus a `$resend` callable
     * that re-invokes the *current* engine (original conversation + appended
     * parts) — the caller's repair loop (T-021) uses it; the router only reacts to
     * the final outcome and books any repair spend onto the run.
     *
     * @param  Closure(string, callable): ValidationOutcome  $validate
     *
     * @throws AllEnginesFailed neither engine produced an accepted result
     * @throws CostCapExceeded the cheapest model still exceeds the per-run cap
     * @throws QuotaExhausted over daily budget and local could not serve
     */
    public function route(
        Share $share,
        GenerationRequest $request,
        Closure $validate,
        ?string $preferredModel = null,
    ): AnalysisRun {
        $preference = $preferredModel
            ?? $share->user->preferred_analysis_model
            ?? 'auto';

        // A pinned *curated* model routes to the remote engine (04 §3 step 1);
        // a pinned local (Ollama) tag just forces the local model — it still
        // goes through the local-first path.
        $remotePin = ($preference !== 'auto' && $this->curated->has($preference)) ? $preference : null;
        $localModel = ($preference !== 'auto' && $remotePin === null)
            ? $preference
            : $this->local->modelFor($request);

        // Over the daily budget: no remote spend is permitted regardless of the
        // preference — local-only, else park the share for a post-midnight retry.
        if ($this->overDailyBudget($share)) {
            return $this->localOnlyOrPark($share, $request, $validate, $localModel);
        }

        if ($remotePin !== null) {
            $run = $this->attemptRemote($share, $request, $validate, $remotePin);
            if ($run->status === AnalysisStatus::Succeeded) {
                return $run;
            }

            throw new AllEnginesFailed("Pinned model '{$remotePin}' failed.");
        }

        // auto or a pinned local model: local-first with the resolved local model.
        $run = $this->attemptLocalOrRecordSkip($share, $request, $validate, $localModel);
        if ($run !== null && $run->status === AnalysisStatus::Succeeded) {
            return $run;
        }

        // Remote fallback.
        $run = $this->attemptRemote($share, $request, $validate, null);
        if ($run->status === AnalysisStatus::Succeeded) {
            return $run;
        }

        throw new AllEnginesFailed("All engines failed for share {$share->id}.");
    }

    /**
     * Local-only path taken when the user is over their daily budget. Returns a
     * succeeded local run, or parks the share (QuotaExhausted) when local is
     * unavailable or could not produce an accepted result.
     *
     * @param  Closure(string, callable): ValidationOutcome  $validate
     */
    private function localOnlyOrPark(Share $share, GenerationRequest $request, Closure $validate, string $localModel): AnalysisRun
    {
        $run = $this->attemptLocalOrRecordSkip($share, $request, $validate, $localModel);
        if ($run !== null && $run->status === AnalysisStatus::Succeeded) {
            return $run;
        }

        throw new QuotaExhausted("Daily budget exhausted for user {$share->user_id}; local unavailable.");
    }

    /**
     * Attempt the local engine when healthy, otherwise record the skipped-local
     * reason and return null so the caller can fall back.
     *
     * @param  Closure(string, callable): ValidationOutcome  $validate
     */
    private function attemptLocalOrRecordSkip(Share $share, GenerationRequest $request, Closure $validate, string $localModel): ?AnalysisRun
    {
        if ($this->local->isHealthy()) {
            return $this->attempt($share, $this->local, $request, $validate, $localModel);
        }

        $this->recordSkippedLocal($share, $localModel, $request->promptVersion);

        return null;
    }

    /**
     * Attempt the remote engine with the cost cap applied, recording spend for
     * any billed run — a remote call that fails validation was still charged.
     *
     * @param  Closure(string, callable): ValidationOutcome  $validate
     */
    private function attemptRemote(Share $share, GenerationRequest $request, Closure $validate, ?string $model): AnalysisRun
    {
        $model = $this->applyCostCap($share, $request, $model ?? (string) config('ai.openrouter.default_model'));

        $run = $this->attempt($share, $this->remote, $request, $validate, $model);

        $cost = (float) $run->cost_usd;
        if ($cost > 0) {
            $this->spend->record($share->user_id, $cost);
        }

        return $run;
    }

    /**
     * Enforce the per-run cost cap: keep the model if it is curated, priced, and
     * fits; else downgrade to the cheapest curated model; else record a blocked
     * run and throw. Fails closed on an unknown/unpriced model — never lets an
     * unpriceable id slip past the cap.
     *
     * @throws CostCapExceeded
     */
    private function applyCostCap(Share $share, GenerationRequest $request, string $model): string
    {
        $max = (float) config('ai.max_cost_per_run', 0.10);

        if ($this->curated->has($model) && $this->estimateCost($model, $request) <= $max) {
            return $model;
        }

        $cheapest = $this->curated->cheapest();
        if ($cheapest !== null && $this->estimateCost((string) $cheapest['id'], $request) <= $max) {
            return (string) $cheapest['id'];
        }

        $run = $this->startRun($share, AnalysisEngineEnum::OpenRouter, $model, $request->promptVersion);
        $this->fail($run, 'cost_cap_exceeded', 'estimated cost exceeds per-run cap');

        throw new CostCapExceeded("Run cost exceeds cap even on the cheapest model (share {$share->id}).");
    }

    private function estimateCost(string $model, GenerationRequest $request): float
    {
        $textChars = mb_strlen($request->systemPrompt);
        $images = 0;
        foreach ($request->userParts as $part) {
            if ($part->isImage()) {
                $images++;
            } else {
                $textChars += mb_strlen((string) $part->text);
            }
        }

        $promptTokens = (int) ceil($textChars / 4) + $images * self::IMAGE_TOKENS;

        return $this->curated->estimateCost($model, $promptTokens, self::COMPLETION_TOKENS);
    }

    private function overDailyBudget(Share $share): bool
    {
        $budget = (float) config('ai.daily_user_budget', 0.50);

        return $this->spend->todaySpendUsd($share->user_id) >= $budget;
    }

    /**
     * Run one engine attempt end to end and persist its outcome. Returns the
     * (succeeded or failed) run — the router decides whether to fall back based
     * on its status. Never throws generation errors upward; they become failed
     * rows carrying the fallback reason in `error`.
     *
     * @param  Closure(string, callable): ValidationOutcome  $validate
     */
    private function attempt(
        Share $share,
        AnalysisEngine $engine,
        GenerationRequest $request,
        Closure $validate,
        ?string $model,
    ): AnalysisRun {
        $run = $this->startRun($share, $engine->name(), $model ?? '(auto)', $request->promptVersion);

        try {
            $result = $engine->generate($request, $model);
        } catch (EngineUnavailable $e) {
            return $this->fail($run, 'ollama_unreachable', $e->getMessage());
        } catch (GenerationFailed $e) {
            return $this->fail($run, 'ollama_error', $e->getMessage());
        }

        // Repair re-sends (driven by the caller's validator) re-invoke this same
        // engine; their spend is billed too, so accumulate it onto this run.
        $repairCost = 0.0;
        $repairInput = 0;
        $repairOutput = 0;
        $resend = function (array $extraParts, float $temperature) use ($engine, $request, $model, &$repairCost, &$repairInput, &$repairOutput): string {
            $repaired = $engine->generate(
                new GenerationRequest(
                    $request->systemPrompt,
                    [...$request->userParts, ...$extraParts],
                    $request->jsonSchema,
                    $temperature,
                    $request->promptVersion,
                ),
                $model,
            );
            $repairCost += $repaired->costUsd;
            $repairInput += $repaired->inputTokens ?? 0;
            $repairOutput += $repaired->outputTokens ?? 0;

            return $repaired->rawText;
        };

        $outcome = $validate($result->rawText, $resend);

        $run->model = $result->model;
        $run->input_tokens = $this->sumTokens($result->inputTokens, $repairInput);
        $run->output_tokens = $this->sumTokens($result->outputTokens, $repairOutput);
        $run->cost_usd = (string) ($result->costUsd + $repairCost);

        if (! $outcome->valid) {
            // Persist raw invalid output nowhere except truncated in `error`.
            return $this->fail($run, 'invalid_json', Str::limit($result->rawText, 2000));
        }

        $run->result_json = $outcome->data;
        $run->overall_confidence = $outcome->confidence !== null ? (string) $outcome->confidence : null;

        $min = (float) config('ai.min_confidence', 0.5);
        if ($outcome->confidence !== null && $outcome->confidence < $min) {
            // Valid output but the model is unsure — escalate. The result_json is
            // schema-valid so it is kept on the failed run for debugging.
            return $this->fail($run, 'low_confidence', null, keepResult: true);
        }

        $run->status = AnalysisStatus::Succeeded;
        $run->finished_at = now();
        $run->save();

        return $run;
    }

    /**
     * Mark a run failed with a fallback reason encoded in `error`
     * (`fallback:{reason}` — the schema has no fallback_reason column, 02 §3.7).
     */
    private function fail(AnalysisRun $run, string $reason, ?string $detail, bool $keepResult = false): AnalysisRun
    {
        if (! $keepResult) {
            $run->result_json = null;
        }

        $error = "fallback:{$reason}";
        if ($detail !== null && $detail !== '') {
            $error .= ' '.$detail;
        }

        $run->status = AnalysisStatus::Failed;
        $run->finished_at = now();
        $run->error = Str::limit($error, 2048, '');
        $run->save();

        return $run;
    }

    /**
     * Record the health-check miss as a failed local run so the
     * `ollama_unreachable` reason is queryable, without issuing a doomed
     * generation call.
     */
    private function recordSkippedLocal(Share $share, string $localModel, ?string $promptVersion): void
    {
        $run = $this->startRun($share, $this->local->name(), $localModel, $promptVersion);

        $this->fail($run, 'ollama_unreachable', 'health check failed');
    }

    /**
     * Sum base + repair token counts, preserving null when nothing is known
     * (so an engine that doesn't report usage stays null rather than 0).
     */
    private function sumTokens(?int $base, int $repair): ?int
    {
        if ($base === null && $repair === 0) {
            return null;
        }

        return ($base ?? 0) + $repair;
    }

    /** Open a `running` run row for one engine attempt. */
    private function startRun(Share $share, AnalysisEngineEnum $engine, string $model, ?string $promptVersion = null): AnalysisRun
    {
        $run = new AnalysisRun([
            'engine' => $engine,
            'model' => $model,
            'status' => AnalysisStatus::Running,
            'started_at' => now(),
        ]);
        $run->prompt_version = $promptVersion;
        $run->share()->associate($share);
        $run->save();

        return $run;
    }
}
