<?php

namespace App\Jobs;

use App\Enums\MediaKind;
use App\Enums\ShareStatus;
use App\Jobs\Concerns\FailsShareOnError;
use App\Jobs\Concerns\RecordsStageMetrics;
use App\Jobs\Concerns\StreamsToDisk;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Models\SourcePost;
use App\Services\Media\Data\ProcessedMedia;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\MediaPaths;
use App\Services\Media\MediaProcessor;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Derives the AI-analysis inputs from a share's original media (04 §1
 * PrepareMedia): a 16 kHz mono WAV (absent when silent — TranscribeAudio then
 * no-ops), ≤12 scene-detected keyframes, and a poster thumbnail — each stored as
 * a media_asset. Workers are stateless, so the original is re-downloaded from the
 * disk into a temp dir that is always cleaned. Idempotent: existing keyframes
 * short-circuit; per-asset sha256 + the unique index guard against re-run dupes.
 */
class PrepareMedia implements ShouldQueue
{
    use Batchable, Dispatchable, FailsShareOnError, InteractsWithQueue, Queueable, RecordsStageMetrics, SerializesModels, StreamsToDisk;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [120, 600];

    public int $timeout = 600;

    public function __construct(public readonly int $shareId)
    {
        $this->onQueue('media');
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["share:{$this->shareId}", 'stage:prepare_media'];
    }

    public function handle(MediaProcessor $processor, FfmpegRunner $ffmpeg): void
    {
        $share = Share::with('sourcePost')->find($this->shareId);

        if ($share === null || $share->status !== ShareStatus::Fetching) {
            return; // guard: not our turn
        }

        $post = $share->sourcePost;

        // Idempotent: derivatives already produced.
        if ($post->mediaAssets()->where('kind', MediaKind::Keyframe->value)->exists()) {
            return;
        }

        $original = $post->mediaAssets()
            ->whereIn('kind', [MediaKind::Video->value, MediaKind::ScreenRecording->value])
            ->first();

        if ($original === null) {
            return; // nothing to prepare (DownloadMedia found no media)
        }

        $this->recordStage($share->id, 'prepare_media');

        $dir = $this->makeTempDir();

        try {
            $localOriginal = $this->pullOriginal($original, $dir);
            $probe = $ffmpeg->probe($localOriginal);
            $processed = $processor->process($localOriginal, $dir, $probe);

            $this->persist($share, $post, $processed, $probe->durationMs);
        } finally {
            $this->cleanTempDir($dir);
        }
    }

    private function persist(Share $share, SourcePost $post, ProcessedMedia $processed, int $durationMs): void
    {
        $shareId = (string) $share->id;

        if ($processed->audioPath !== null) {
            $this->store($post, MediaKind::Audio, $processed->audioPath, MediaPaths::audio($shareId), 'audio/wav', [
                'duration_ms' => $durationMs,
            ]);
        }

        foreach ($processed->frames as $frame) {
            [$width, $height] = $this->imageSize($frame->path);
            $this->store($post, MediaKind::Keyframe, $frame->path, MediaPaths::frame($shareId, $frame->index, $frame->atMs), 'image/jpeg', [
                'frame_at_ms' => $frame->atMs,
                'width' => $width,
                'height' => $height,
            ]);
        }

        [$tw, $th] = $this->imageSize($processed->thumbnailPath);
        $this->store($post, MediaKind::Thumbnail, $processed->thumbnailPath, MediaPaths::thumbnail($shareId), 'image/jpeg', [
            'width' => $tw,
            'height' => $th,
        ]);
    }

    /**
     * Upload a derivative to the media disk and record its media_asset row. Keyed
     * on (sha256, source_post_id) so a re-run never duplicates.
     *
     * @param  array<string, int|null>  $extra
     */
    private function store(SourcePost $post, MediaKind $kind, string $localPath, string $storagePath, string $mime, array $extra = []): void
    {
        $disk = (string) config('media.disk');
        $sha256 = (string) hash_file('sha256', $localPath);

        $this->writeStreamFromFile($disk, $storagePath, $localPath);

        MediaAsset::firstOrCreate(
            ['sha256' => $sha256, 'source_post_id' => $post->id],
            array_merge([
                'kind' => $kind,
                'storage_path' => $storagePath,
                'disk' => $disk,
                'mime' => $mime,
                'bytes' => filesize($localPath) ?: 0,
            ], $extra),
        );
    }

    private function pullOriginal(MediaAsset $original, string $dir): string
    {
        $local = "{$dir}/original";
        $stream = Storage::disk($original->disk)->readStream($original->storage_path);
        $out = fopen($local, 'wb');
        if (is_resource($stream) && is_resource($out)) {
            stream_copy_to_stream($stream, $out);
        }
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (is_resource($out)) {
            fclose($out);
        }

        return $local;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function imageSize(string $path): array
    {
        $size = @getimagesize($path);

        return $size === false ? [null, null] : [$size[0], $size[1]];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/prepare_'.$this->shareId.'_'.bin2hex(random_bytes(4));
        @mkdir($dir, 0755, true);

        return $dir;
    }

    private function cleanTempDir(string $dir): void
    {
        foreach (glob("{$dir}/{,.}*", GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path) && ! in_array(basename($path), ['.', '..'], true)) {
                foreach (glob("{$path}/*") ?: [] as $inner) {
                    @unlink($inner);
                }
                @rmdir($path);
            }
        }
        @rmdir($dir);
    }

    protected function failureCode(): string
    {
        return 'ffmpeg_error';
    }
}
