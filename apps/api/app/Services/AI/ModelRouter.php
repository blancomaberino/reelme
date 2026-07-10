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
use App\Services\AI\Exceptions\EngineUnavailable;
use App\Services\AI\Exceptions\GenerationFailed;
use Closure;
use Illuminate\Support\Str;

/**
 * The single entry point for extraction LLM calls (04 §3). Local-first with
 * automatic remote fallback; every attempt — success or failure — is persisted
 * as an `analysis_runs` row so all spend and outcomes are queryable without a
 * separate ledger. Stateless and queue-safe: the enclosing job owns retries.
 */
class ModelRouter
{
    public function __construct(
        private readonly LocalEngine $local,
        private readonly AnalysisEngine $remote,
    ) {}

    /**
     * Route a generation request for a share and return the winning (succeeded)
     * run. `$validate` is invoked on each engine's raw output — its repair loop
     * lives in the caller (T-021); the router only reacts to the outcome.
     *
     * @param  Closure(string): ValidationOutcome  $validate
     *
     * @throws AllEnginesFailed when neither engine produced an accepted result
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

        // A pinned model skips local and goes straight to the remote engine
        // with that model (04 §3 step 1).
        if ($preference !== 'auto') {
            $run = $this->attempt($share, $this->remote, $request, $validate, $preference);
            if ($run->status === AnalysisStatus::Succeeded) {
                return $run;
            }

            throw new AllEnginesFailed("Pinned model '{$preference}' failed.");
        }

        // auto: local-first. An unhealthy host skips the generation call but
        // still records the reason as a failed local run.
        if ($this->local->isHealthy()) {
            $run = $this->attempt($share, $this->local, $request, $validate, $this->local->modelFor($request));
            if ($run->status === AnalysisStatus::Succeeded) {
                return $run;
            }
        } else {
            $this->recordSkippedLocal($share, $request);
        }

        // Remote fallback.
        $run = $this->attempt($share, $this->remote, $request, $validate, null);
        if ($run->status === AnalysisStatus::Succeeded) {
            return $run;
        }

        throw new AllEnginesFailed("All engines failed for share {$share->id}.");
    }

    /**
     * Run one engine attempt end to end and persist its outcome. Returns the
     * (succeeded or failed) run — the router decides whether to fall back based
     * on its status. Never throws generation errors upward; they become failed
     * rows carrying the fallback reason in `error`.
     *
     * @param  Closure(string): ValidationOutcome  $validate
     */
    private function attempt(
        Share $share,
        AnalysisEngine $engine,
        GenerationRequest $request,
        Closure $validate,
        ?string $model,
    ): AnalysisRun {
        $run = $this->startRun($share, $engine->name(), $model ?? '(auto)');

        try {
            $result = $engine->generate($request, $model);
        } catch (EngineUnavailable $e) {
            return $this->fail($run, 'ollama_unreachable', $e->getMessage());
        } catch (GenerationFailed $e) {
            return $this->fail($run, 'ollama_error', $e->getMessage());
        }

        $run->model = $result->model;
        $run->input_tokens = $result->inputTokens;
        $run->output_tokens = $result->outputTokens;
        $run->cost_usd = (string) $result->costUsd;

        $outcome = $validate($result->rawText);

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
    private function recordSkippedLocal(Share $share, GenerationRequest $request): void
    {
        $run = $this->startRun($share, $this->local->name(), $this->local->modelFor($request));

        $this->fail($run, 'ollama_unreachable', 'health check failed');
    }

    /** Open a `running` run row for one engine attempt. */
    private function startRun(Share $share, AnalysisEngineEnum $engine, string $model): AnalysisRun
    {
        $run = new AnalysisRun([
            'engine' => $engine,
            'model' => $model,
            'status' => AnalysisStatus::Running,
            'started_at' => now(),
        ]);
        $run->share()->associate($share);
        $run->save();

        return $run;
    }
}
