<?php

namespace App\Jobs;

use App\Enums\MediaKind;
use App\Enums\ShareStatus;
use App\Jobs\Concerns\FailsShareOnError;
use App\Jobs\Concerns\RecordsStageMetrics;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Models\SourcePost;
use App\Services\Transcription\Data\TranscriptionResult;
use App\Services\Transcription\TranscriptionManager;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Transcribes a share's audio into source_posts.transcript_json (04 §1), which
 * feeds the T-021 extraction prompt. Local-first via the TranscriptionManager
 * with a hosted fallback. The transcript lives on the source_post — shared across
 * every share of the same reel — so it is written once and reused (idempotent).
 * Silent videos (no audio asset) store an empty marker and proceed; silence is
 * not a failure.
 */
class TranscribeAudio implements ShouldQueue
{
    use Batchable, Dispatchable, FailsShareOnError, InteractsWithQueue, Queueable, RecordsStageMetrics, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 900;

    public function __construct(public readonly int $shareId)
    {
        $this->onQueue('media');
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["share:{$this->shareId}", 'stage:transcribe'];
    }

    public function handle(TranscriptionManager $manager): void
    {
        $share = Share::with('sourcePost')->find($this->shareId);

        if ($share === null || $share->status !== ShareStatus::Fetching) {
            return; // guard: not our turn
        }

        $post = $share->sourcePost;

        // Idempotent + shared across reels: another share already transcribed it.
        if ($post->transcript_json !== null) {
            return;
        }

        $this->recordStage($share->id, 'transcribe');

        $audio = $post->mediaAssets()->where('kind', MediaKind::Audio->value)->first();

        if ($audio === null) {
            // Silent/no-audio (T-017 emitted no WAV) — empty transcript, not a failure.
            $this->persist($post, TranscriptionResult::empty('none'));

            return;
        }

        $tmp = $this->pullAudio($audio);

        try {
            $this->persist($post, $manager->transcribe($tmp, $share->id));
        } finally {
            @unlink($tmp);
        }
    }

    /** Concurrency-safe write: only the first share of a post wins the transcript. */
    private function persist(SourcePost $post, TranscriptionResult $result): void
    {
        SourcePost::query()
            ->whereKey($post->id)
            ->whereNull('transcript_json')
            ->update(['transcript_json' => json_encode($result->toArray())]);
    }

    private function pullAudio(MediaAsset $audio): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'wav_');
        $stream = Storage::disk($audio->disk)->readStream($audio->storage_path);
        $out = fopen($tmp, 'wb');
        if (is_resource($stream) && is_resource($out)) {
            stream_copy_to_stream($stream, $out);
        }
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (is_resource($out)) {
            fclose($out);
        }

        return $tmp;
    }

    protected function failureCode(): string
    {
        return 'transcribe_error';
    }
}
