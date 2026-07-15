<?php

namespace App\Http\Resources;

use App\Enums\ShareStatus;
use App\Jobs\Pipeline;
use App\Models\Place;
use App\Models\Share;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /shares/{id} shape (03-api-design §3.2). IDs serialize as strings (§1);
 * the DB uses bigint PKs (see PR note re the spec's shr_ prefix divergence).
 *
 * @mixin Share
 */
class ShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $post = $this->sourcePost;
        $run = $this->analysisRuns->sortByDesc('id')->first();

        return [
            'id' => (string) $this->id,
            'status' => $this->status->value,
            'status_history' => $this->statusHistory(),
            'source_post' => [
                'id' => (string) $post->id,
                'platform' => $post->platform->value,
                'url' => $post->url,
                'author_handle' => $post->influencer?->handle,
                'caption' => $post->caption,
                'fetch_status' => $post->fetch_status->value,
            ],
            'analysis' => $run === null ? null : [
                'run_id' => (string) $run->id,
                'model' => $run->model,
                'status' => $run->status->value,
                'confidence' => $run->overall_confidence !== null ? (float) $run->overall_confidence : null,
                'extraction' => $run->result_json,
            ],
            'failure' => $this->failurePayload(),
            // `place` = the primary published pin (back-compat single-place clients);
            // `places` = EVERY published pin (a multi-place post resolves to several).
            'place' => $this->placePayload(),
            'places' => $this->placesPayload(),
            // How many extracted venues are still parked for review (partial publish).
            'pending_place_count' => $this->pendingPlaceCount(),
        ];
    }

    /**
     * The primary published place (with coordinates) so a client can drop/centre a
     * pin without a separate map query. Null until the share publishes.
     *
     * @return array{id: string, name: string, lat: float, lng: float}|null
     */
    private function placePayload(): ?array
    {
        return $this->placeCoords($this->publishedPlaceSource?->place);
    }

    /**
     * Every published place for this share (a multi-place post fans out to N),
     * primary first, deduped. Empty until the share publishes.
     *
     * @return list<array{id: string, name: string, lat: float, lng: float}>
     */
    private function placesPayload(): array
    {
        $primaryId = $this->published_place_source_id;

        return $this->publishedPlaceSources
            ->sortByDesc(fn ($source) => $source->id === $primaryId ? 1 : 0)
            ->map(fn ($source) => $source->place)
            ->filter()
            ->unique('id')
            ->map(fn ($place) => $this->placeCoords($place))
            ->filter()
            ->values()
            ->all();
    }

    private function pendingPlaceCount(): int
    {
        $pending = is_array($this->review_meta_json) ? ($this->review_meta_json['pending'] ?? null) : null;

        return is_array($pending) ? count($pending) : 0;
    }

    /**
     * @return array{id: string, name: string, lat: float, lng: float}|null
     */
    private function placeCoords(?Place $place): ?array
    {
        if ($place === null) {
            return null;
        }

        $coords = $place->coordinates();

        return [
            'id' => (string) $place->id,
            'name' => $place->name,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
        ];
    }

    /**
     * Derived from share_stage_metrics + created_at (03 §3.2). Emits a checkpoint
     * whenever the derived status changes, ending at the current status.
     *
     * @return array<int, array{status: string, at: string|null}>
     */
    private function statusHistory(): array
    {
        $history = [[
            'status' => ShareStatus::Pending->value,
            'at' => $this->created_at?->toIso8601ZuluString(),
        ]];

        $metrics = $this->stageMetrics->sortBy('id');
        foreach ($metrics as $metric) {
            // Single source of truth for the stage→status partition (Pipeline).
            $status = Pipeline::entryStatus($metric->stage);
            if (end($history)['status'] !== $status->value) {
                $history[] = ['status' => $status->value, 'at' => $metric->started_at?->toIso8601ZuluString()];
            }
        }

        if (end($history)['status'] !== $this->status->value) {
            $history[] = ['status' => $this->status->value, 'at' => $this->updated_at?->toIso8601ZuluString()];
        }

        return $history;
    }

    /**
     * @return array{code: string, step: string|null, message: string, manual_fallback: bool}|null
     */
    private function failurePayload(): ?array
    {
        if (! in_array($this->status, [ShareStatus::Failed, ShareStatus::Review], true) || $this->failure_reason === null) {
            return null;
        }

        $lastStage = $this->stageMetrics->sortByDesc('id')->first()?->stage;

        return [
            'code' => $this->failure_reason,
            'step' => $lastStage,
            'message' => self::humanize($this->failure_reason),
            'manual_fallback' => $this->status === ShareStatus::Review,
        ];
    }

    private static function humanize(string $code): string
    {
        return match ($code) {
            'fetch_unavailable' => "We couldn't fetch this post. Add a caption and screen recording to continue.",
            'fetch_auth_required' => 'This post is private. Link the account or upload it manually.',
            'media_too_large' => 'The video is too large to process.',
            'ffmpeg_error', 'transcribe_error' => "We couldn't process the video.",
            'ollama_unreachable', 'invalid_model_output' => 'Analysis failed. Please try again.',
            'cost_cap_exceeded' => 'Daily analysis limit reached. Try again tomorrow.',
            'geocode_failed', 'resolve_conflict' => "We couldn't pin this place.",
            default => 'Something went wrong.',
        };
    }
}
