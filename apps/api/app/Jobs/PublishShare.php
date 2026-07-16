<?php

namespace App\Jobs;

use App\Enums\ShareStatus;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Places\PlacePublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Terminal pipeline stage (04 §7): freezes the publish-time snapshot, flips the
 * share to `published`, activates the place per the second-source/user-confirm
 * rule, and rolls the place counters. Idempotent across Horizon redelivery — the
 * one-time writes are gated on winning the optimistic analyzing→published guard.
 */
class PublishShare extends PipelineStubJob
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public int $timeout = 30;

    protected function stage(): string
    {
        return 'publish';
    }

    protected function queueName(): string
    {
        return 'publish';
    }

    protected function expectedStatus(): ShareStatus
    {
        return ShareStatus::Analyzing;
    }

    protected function run(Share $share): void
    {
        // Defensive idempotency (PipelineStubJob already guards on Analyzing).
        if ($share->status === ShareStatus::Published) {
            return;
        }

        /** @var Collection<int, PlaceSource> $sources */
        $sources = PlaceSource::query()->where('share_id', $share->id)->get();
        if ($sources->isEmpty()) {
            return; // resolve parked the share to review — nothing to publish yet
        }

        // Freeze the as-published snapshot for a single-place share the user
        // amended in review; a multi-place share keeps each source's resolver
        // snapshot (per-place review corrections are applied at resolve time).
        if ($sources->count() === 1 && is_array($share->corrected_extraction_json)) {
            $corrected = $this->correctedPlace($share->corrected_extraction_json);
            if ($corrected !== null) {
                $sources->first()->extraction_snapshot_json = $corrected;
                $sources->first()->save();
            }
        }

        $now = now();
        // The primary source (or first) drives the map "jump to pin" affordance.
        $primary = $sources->firstWhere('is_primary', true) ?? $sources->first();

        // Atomically flip the share to published AND mark every source live AND set
        // the primary pin — a throw rolls it all back so the analyzing→published
        // guard is re-armed and the retry redoes it, never a half-published share
        // (some sources live, primary null). Only the worker that wins the
        // optimistic transition commits these one-time writes.
        $won = DB::transaction(function () use ($share, $sources, $now, $primary): bool {
            if (! $share->transitionTo(ShareStatus::Published)) {
                return false;
            }
            foreach ($sources as $source) {
                $source->published_at = $now;
                $source->save();
            }
            $share->published_place_source_id = $primary->id;
            $share->save();

            return true;
        });

        if (! $won) {
            return; // a redelivered/concurrent job already published this share
        }

        // Counters/activation + tag materialization are idempotent recomputes with
        // external side effects (Scout), so they run AFTER the publish commit and
        // are best-effort per source — a failure on one place never strands the
        // share or blocks its siblings (recoverable via the tags/counters backfill).
        foreach ($sources as $source) {
            try {
                $this->activateAndCount($source, $share);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * The corrected place payload for a single-place share (places[] first entry,
     * or the pre-v6 singular place).
     *
     * @param  array<string, mixed>  $corrected
     * @return array<string, mixed>|null
     */
    private function correctedPlace(array $corrected): ?array
    {
        if (is_array($corrected['places'][0] ?? null)) {
            return $corrected['places'][0];
        }

        return is_array($corrected['place'] ?? null) ? $corrected['place'] : null;
    }

    /**
     * A published source rolls the place's activation + counters + tags. The
     * bookkeeping lives in {@see PlacePublisher} so the pending-venue resolve path
     * (T-071) recomputes identically and the two can't drift.
     */
    private function activateAndCount(PlaceSource $source, Share $share): void
    {
        $place = $source->place;
        if ($place === null) {
            return;
        }

        app(PlacePublisher::class)->recompute($place, $share, $source);
    }

    public function failed(Throwable $e): void
    {
        $share = Share::find($this->shareId);

        if ($share === null || ! $share->canTransitionTo(ShareStatus::Failed)) {
            return;
        }

        $share->transitionTo(ShareStatus::Failed, 'publish_error');
    }
}
