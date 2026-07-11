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
        return 'analysis';
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

        $outcome = app(PlaceResolver::class)->resolve($share);

        match ($outcome->type) {
            ResolutionOutcome::ATTACHED, ResolutionOutcome::CREATED => null, // stay analyzing → PublishShare
            ResolutionOutcome::AMBIGUOUS => $this->toReview($share, 'ambiguous_place', ['candidates' => $outcome->candidates]),
            default => $this->toReview($share, 'geocode_failed', null), // GEOCODE_FAILED
        };
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
