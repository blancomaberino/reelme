<?php

namespace App\Jobs;

use App\Enums\ShareStatus;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Geo\Exceptions\GeocodeFailed;
use App\Services\Places\PlaceResolver;
use App\Services\Places\ResolutionOutcome;
use Throwable;

/**
 * Resolves a share's extracted place to a canonical map pin (04 §6). Runs the
 * dedup tree via PlaceResolver: attach/create → continue the chain to
 * PublishShare; ambiguous or geocode-miss → park the share for human review.
 * Idempotent: a re-delivery whose share already has a place_source no-ops.
 */
class ResolvePlace extends PipelineStubJob
{
    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    protected function stage(): string
    {
        return 'resolve';
    }

    protected function queueName(): string
    {
        return 'resolve';
    }

    protected function expectedStatus(): ShareStatus
    {
        return ShareStatus::Analyzing;
    }

    protected function run(Share $share): void
    {
        if (PlaceSource::query()->where('share_id', $share->id)->exists()) {
            return; // already resolved — let the chain continue to PublishShare
        }

        // Resolve every extracted place. Attached/created ones become (unpublished)
        // place_sources; the rest are recorded as pending review candidates.
        $results = app(PlaceResolver::class)->resolveAll($share);

        $resolvedCount = 0;
        $pending = [];
        foreach ($results as $r) {
            /** @var ResolutionOutcome $outcome */
            $outcome = $r['outcome'];
            if (in_array($outcome->type, [ResolutionOutcome::ATTACHED, ResolutionOutcome::CREATED], true)) {
                $resolvedCount++;

                continue;
            }
            $pending[] = [
                'index' => $r['index'],
                'name' => $r['name'],
                'reason' => match ($outcome->type) {
                    ResolutionOutcome::AMBIGUOUS => 'ambiguous_place',
                    ResolutionOutcome::HIDDEN_MATCH => 'place_hidden',
                    default => 'geocode_failed',
                },
                'candidates' => $outcome->candidates,
            ];
        }

        // Nothing resolved → park the whole share for review (single-place picker
        // shape preserved for the one-place case so the existing review UI works).
        if ($resolvedCount === 0) {
            $first = $pending[0] ?? ['reason' => 'geocode_failed', 'candidates' => []];
            $meta = ['pending' => $pending];
            if ($first['reason'] === 'ambiguous_place') {
                $meta['candidates'] = $first['candidates']; // back-compat single-place picker
            }
            $this->toReview($share, $first['reason'], $meta);

            return;
        }

        // At least one place resolved → continue the chain to PublishShare. Record
        // any unresolved places so the review UI can surface them WITHOUT blocking
        // the ones that published (partial publish).
        $share->review_meta_json = $pending !== [] ? ['pending' => $pending] : null;
        $share->review_reason = null;
        $share->save();
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function toReview(Share $share, string $reason, ?array $meta): void
    {
        $share->review_reason = $reason;
        $share->review_meta_json = $meta;
        $share->save();
        $share->transitionTo(ShareStatus::Review);
    }

    public function failed(Throwable $e): void
    {
        $share = Share::find($this->shareId);

        if ($share === null || ! $share->canTransitionTo(ShareStatus::Failed)) {
            return;
        }

        $share->transitionTo(ShareStatus::Failed, $e instanceof GeocodeFailed ? 'geocode_failed' : 'resolve_conflict');
    }
}
