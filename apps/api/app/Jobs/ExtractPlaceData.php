<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Enums\ShareStatus;
use App\Models\AnalysisRun;
use App\Models\Share;
use App\Services\AI\Data\GenerationPart;
use App\Services\AI\Data\ValidationOutcome;
use App\Services\AI\Exceptions\AllEnginesFailed;
use App\Services\AI\Exceptions\CostCapExceeded;
use App\Services\AI\Exceptions\QuotaExhausted;
use App\Services\AI\ModelRouter;
use App\Services\AI\Prompts\ExtractionPromptBuilder;
use App\Services\Places\CardIssuerResolver;
use App\Support\Contracts\ExtractionSchema;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * The pipeline's brain (04 §5): assembles the multimodal prompt, drives the
 * ModelRouter (local-first with remote fallback, all spend booked as
 * analysis_runs), enforces the extraction schema with a bounded repair loop, and
 * gates the share toward `review` or onward to ResolvePlace.
 *
 * Status handling: this stage runs while the share is `fetching` and only
 * advances it on success — to `analyzing` (chain continues) or `review` (parked).
 * Keeping the share `fetching` through the LLM call means a transport retry
 * re-enters here (PipelineStubJob's status guard), while the succeeded-run
 * short-circuit prevents a re-run from spending on a second generation.
 */
class ExtractPlaceData extends PipelineStubJob
{
    /** Repair re-sends allowed per engine attempt, inside one job execution (04 §5). */
    private const MAX_REPAIRS = 2;

    /** Default overall-confidence floor to auto-continue; tunable via config. */
    private const DEFAULT_MIN_PUBLISH_CONFIDENCE = 0.75;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [30, 180, 600];

    /**
     * @param  bool  $force  Admin reprocess (T-072): re-run the LLM even when a
     *                       prior succeeded run exists, instead of reusing it.
     */
    public function __construct(int $shareId, public readonly bool $force = false)
    {
        parent::__construct($shareId);
    }

    protected function stage(): string
    {
        return 'extract';
    }

    protected function queueName(): string
    {
        return 'analyze';
    }

    protected function expectedStatus(): ShareStatus
    {
        return ShareStatus::Fetching;
    }

    protected function run(Share $share): void
    {
        $share->loadMissing('sourcePost.influencer');

        if (! $this->force && ($existing = $this->existingSuccess($share)) !== null) {
            // Re-delivery: the prior success was already issuer-enriched on its
            // first pass — don't re-run it (a dead handle would re-hit Instagram
            // every retry).
            $this->gate($share, $existing);

            return;
        }

        try {
            $run = $this->analyze($share);
        } catch (CostCapExceeded|QuotaExhausted $e) {
            // Deterministic within the retry/backoff window (a daily budget won't
            // reset in 600s) — park on the first pass instead of retrying 3×.
            $share->transitionTo(ShareStatus::Failed, $e instanceof CostCapExceeded ? 'cost_cap_exceeded' : 'quota_exhausted');

            return;
        } catch (AllEnginesFailed $e) {
            // A schema-valid but low-confidence result (kept by the router on a
            // failed run) is reviewable, not a hard failure — salvage it. It is
            // being gated for the FIRST time here, so enrich it too (T-079).
            $salvage = $this->salvageableRun($share);
            if ($salvage !== null) {
                $this->resolveDiscountIssuers($salvage);
                $this->gate($share, $salvage);

                return;
            }

            throw $e; // genuinely unusable output — retry per job policy, then failed()
        }

        // T-079: fill each discount's issuer name from its @handle before the
        // snapshot is frozen. On the newly produced run only (fresh + salvage) —
        // a re-delivered success above was enriched on its first pass.
        $this->resolveDiscountIssuers($run);
        $this->gate($share, $run);
    }

    /**
     * When a caption attributed a card discount to a bank only by an @mention,
     * resolve that issuer's display name from its Instagram profile (T-079) and
     * write it back onto the run's result so the frozen place_source snapshot
     * carries "Santander", not just "@santander.uy". Config-gated; never throws
     * (a dead profile / missing cookie leaves the @handle fallback in place).
     */
    private function resolveDiscountIssuers(AnalysisRun $run): void
    {
        if (! (bool) config('places.card_discounts.resolve_issuer', true)) {
            return;
        }

        $result = $run->result_json;
        if (! is_array($result) || ! is_array($result['places'] ?? null)) {
            return;
        }

        $resolver = app(CardIssuerResolver::class);
        $changed = false;

        foreach ($result['places'] as $pi => $place) {
            if (! is_array($place) || ! is_array($place['discounts'] ?? null)) {
                continue;
            }
            foreach ($place['discounts'] as $di => $discount) {
                if (! is_array($discount)) {
                    continue;
                }
                $handle = trim((string) ($discount['handle'] ?? ''));
                $issuer = trim((string) ($discount['issuer'] ?? ''));
                if ($handle === '' || $issuer !== '') {
                    continue; // no handle, or already named in plain text
                }
                $name = $resolver->resolve($handle);
                if ($name !== null) {
                    $result['places'][$pi]['discounts'][$di]['issuer'] = $name;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $run->result_json = $result;
            $run->save();
        }
    }

    /**
     * Map a router failure to the share failure taxonomy. Overrides the trait's
     * generic handler so cost/quota parks read differently from a dead model.
     */
    public function failed(Throwable $e): void
    {
        $share = Share::find($this->shareId);

        if ($share === null || ! $share->canTransitionTo(ShareStatus::Failed)) {
            return;
        }

        $share->transitionTo(ShareStatus::Failed, match (true) {
            $e instanceof CostCapExceeded => 'cost_cap_exceeded',
            $e instanceof QuotaExhausted => 'quota_exhausted',
            default => 'invalid_model_output',
        });
    }

    /** A prior succeeded run makes re-delivery idempotent — reuse it, don't re-spend. */
    private function existingSuccess(Share $share): ?AnalysisRun
    {
        return $share->analysisRuns()
            ->where('status', AnalysisStatus::Succeeded->value)
            ->latest('id')
            ->first();
    }

    /**
     * The most recent run that carries a schema-valid payload. The router keeps
     * `result_json` on a run it failed only for low confidence, so this recovers
     * an extraction worth a human's review after both engines dead-ended.
     */
    private function salvageableRun(Share $share): ?AnalysisRun
    {
        return $share->analysisRuns()
            ->whereNotNull('result_json')
            ->latest('id')
            ->first();
    }

    private function analyze(Share $share): AnalysisRun
    {
        $request = app(ExtractionPromptBuilder::class)->build($share);

        return app(ModelRouter::class)->route($share, $request, $this->validator());
    }

    /**
     * The per-attempt validator with the bounded repair loop. `$resend` re-invokes
     * the router's *current* engine with the original conversation plus a repair
     * instruction, so a local dead-end repairs locally and a remote one remotely.
     *
     * @return Closure(string, callable): ValidationOutcome
     */
    private function validator(): Closure
    {
        return function (string $raw, callable $resend): ValidationOutcome {
            $current = $raw;

            for ($attempt = 0; ; $attempt++) {
                $decoded = $this->extractJson($current);

                if ($decoded !== null) {
                    $result = ExtractionSchema::validate($decoded);

                    if ($result->isValid()) {
                        $overall = $decoded['confidence']['overall'] ?? null;

                        return ValidationOutcome::valid($decoded, is_numeric($overall) ? (float) $overall : null);
                    }

                    $errors = ExtractionSchema::errors($result);
                } else {
                    $errors = ['$' => ['Response was not parseable JSON.']];
                }

                if ($attempt >= self::MAX_REPAIRS) {
                    return ValidationOutcome::invalid();
                }

                try {
                    $current = $resend([GenerationPart::text($this->repairMessage($current, $errors))], 0.0);
                } catch (Throwable) {
                    return ValidationOutcome::invalid(); // repair transport failed — this engine is dead
                }
            }
        };
    }

    /**
     * Persist the winning run pointer and route the share: no place → review
     * (`no_place_extracted`); low confidence → review; otherwise continue the
     * chain to ResolvePlace by advancing to `analyzing`.
     */
    private function gate(Share $share, AnalysisRun $run): void
    {
        $share->analysis_run_id = $run->id;
        $share->save();

        $result = $run->result_json ?? [];
        $overall = $run->overall_confidence !== null ? (float) $run->overall_confidence : 0.0;

        // At least one identifiable venue (a multi-place post has several).
        if (! $this->hasNamedPlace($result)) {
            $this->toReview($share, 'no_place_extracted');

            return;
        }

        $minConfidence = (float) config('ai.min_publish_confidence', self::DEFAULT_MIN_PUBLISH_CONFIDENCE);
        if ($overall < $minConfidence) {
            $this->toReview($share, 'low_confidence');

            return;
        }

        // Clear any stale review reason before continuing the chain to ResolvePlace.
        $share->review_reason = null;
        $share->save();
        $share->transitionTo(ShareStatus::Analyzing);
    }

    private function toReview(Share $share, string $reason): void
    {
        $share->review_reason = $reason;
        $share->save();
        $share->transitionTo(ShareStatus::Review);
    }

    /**
     * Whether the extraction produced at least one identifiable venue. Reads
     * places[] (v6+); tolerates a pre-v6 singular place.
     *
     * @param  array<string, mixed>  $result
     */
    private function hasNamedPlace(array $result): bool
    {
        $places = is_array($result['places'] ?? null)
            ? $result['places']
            : (is_array($result['place'] ?? null) ? [$result['place']] : []);

        foreach ($places as $place) {
            if (is_array($place) && trim((string) ($place['name'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function repairMessage(string $previous, array $errors): string
    {
        $summary = (string) json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "Your previous response was:\n".Str::limit($previous, 4000)
            ."\n\nYour previous output failed validation: {$summary}. Return the corrected JSON object only.";
    }

    /**
     * Tolerant extraction: strip a markdown fence, then take the first *balanced*
     * `{…}` object (not a greedy regex), so leading prose or a trailing echo of
     * the schema doesn't defeat the parse.
     *
     * @return array<string, mixed>|null
     */
    private function extractJson(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        $block = $this->firstBalancedObject($text);
        if ($block === null) {
            return null;
        }

        $decoded = json_decode($block, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function firstBalancedObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
