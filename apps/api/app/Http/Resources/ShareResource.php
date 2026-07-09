<?php

namespace App\Http\Resources;

use App\Enums\ShareStatus;
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
            $status = $this->statusForStage($metric->stage);
            if ($status !== null && end($history)['status'] !== $status->value) {
                $history[] = ['status' => $status->value, 'at' => $metric->started_at?->toIso8601ZuluString()];
            }
        }

        if (end($history)['status'] !== $this->status->value) {
            $history[] = ['status' => $this->status->value, 'at' => $this->updated_at?->toIso8601ZuluString()];
        }

        return $history;
    }

    private function statusForStage(string $stage): ?ShareStatus
    {
        return match ($stage) {
            'ingest', 'fetch', 'download', 'prepare', 'transcribe' => ShareStatus::Fetching,
            'extract', 'resolve', 'publish' => ShareStatus::Analyzing,
            default => null,
        };
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
