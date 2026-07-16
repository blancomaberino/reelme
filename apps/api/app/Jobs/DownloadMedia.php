<?php

namespace App\Jobs;

use App\Adapters\AdapterRegistry;
use App\Adapters\Data\FetchedMedia;
use App\Adapters\Data\SourcePostData;
use App\Enums\MediaKind;
use App\Enums\ShareStatus;
use App\Jobs\Concerns\FailsShareOnError;
use App\Jobs\Concerns\RecordsStageMetrics;
use App\Jobs\Concerns\StreamsToDisk;
use App\Models\MediaAsset;
use App\Models\Share;
use App\Models\SourcePost;
use App\Services\Media\FfmpegRunner;
use App\Services\Media\MediaPaths;
use App\Services\Media\MediaTooLarge;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Pulls the adapter-resolved original media onto the originals disk as a `video`
 * media_asset (04 §1 DownloadMedia). Streams to a temp file (never buffers the
 * whole file in memory), enforces the 500 MB / 15 min caps, dedups by sha256,
 * and records ffprobe metadata. Idempotent: an existing video/screen_recording
 * original short-circuits (manual shares already have their recording).
 */
class DownloadMedia implements ShouldQueue
{
    use Batchable, Dispatchable, FailsShareOnError, InteractsWithQueue, Queueable, RecordsStageMetrics, SerializesModels, StreamsToDisk;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 600;

    public function __construct(public readonly int $shareId)
    {
        $this->onQueue('ingest');
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["share:{$this->shareId}", 'stage:download'];
    }

    public function handle(AdapterRegistry $registry, FfmpegRunner $ffmpeg): void
    {
        $share = Share::with('sourcePost')->find($this->shareId);

        if ($share === null || $share->status !== ShareStatus::Fetching) {
            return; // guard: not our turn
        }

        $post = $share->sourcePost;

        // Idempotent: the original is already present (manual shares upload a
        // screen_recording during ingest; a re-run finds the video).
        if ($post->mediaAssets()->whereIn('kind', [MediaKind::Video->value, MediaKind::ScreenRecording->value])->exists()) {
            return;
        }

        $this->recordStage($share->id, 'download');

        $media = $this->firstDownloadable($registry, $post);
        if ($media === null) {
            return; // nothing to download (chain resolved no video)
        }

        try {
            $this->ingest($share, $post, $media, $ffmpeg);
        } catch (MediaTooLarge) {
            // Permanent — do not retry; park the share as failed with the code.
            if ($share->canTransitionTo(ShareStatus::Failed)) {
                $share->transitionTo(ShareStatus::Failed, 'media_too_large');
            }
        }
    }

    private function firstDownloadable(AdapterRegistry $registry, SourcePost $post): ?FetchedMedia
    {
        $data = new SourcePostData(
            platform: $post->platform,
            externalId: $post->external_id,
            url: $post->url,
            caption: $post->caption,
        );

        foreach ($registry->resolve($post->url) as $adapter) {
            foreach ($adapter->fetchMedia($data, null)->media as $media) {
                if (in_array($media->kind, [MediaKind::Video, MediaKind::ScreenRecording], true)) {
                    return $media;
                }
            }
        }

        return null;
    }

    private function ingest(Share $share, SourcePost $post, FetchedMedia $media, FfmpegRunner $ffmpeg): void
    {
        $tmp = $this->toTempFile($media);

        try {
            $bytes = filesize($tmp) ?: 0;
            if ($bytes > (int) config('media.max_download_bytes')) {
                throw new MediaTooLarge("Media is {$bytes} bytes.");
            }

            $probe = $ffmpeg->probe($tmp);
            if ($probe->durationMs > (int) config('media.max_duration_ms')) {
                throw new MediaTooLarge("Media is {$probe->durationMs} ms long.");
            }

            $sha256 = hash_file('sha256', $tmp);

            // Dedup: identical bytes already stored for this post → skip re-upload.
            if ($post->mediaAssets()->where('sha256', $sha256)->exists()) {
                return;
            }

            $disk = (string) config('media.originals_disk');
            $path = MediaPaths::original((string) $share->id, (string) $sha256, $this->extensionFor($media->mime));

            $this->writeStreamFromFile($disk, $path, $tmp);

            MediaAsset::create([
                'source_post_id' => $post->id,
                'kind' => MediaKind::Video,
                'storage_path' => $path,
                'disk' => $disk,
                'mime' => $media->mime ?? 'video/mp4',
                'bytes' => $bytes,
                'duration_ms' => $probe->durationMs,
                'width' => $probe->width,
                'height' => $probe->height,
                'sha256' => $sha256,
            ]);
        } finally {
            // Always clean the temp original — both a streamed remote body and a
            // yt-dlp-downloaded local file (T-074). The bytes are already on the
            // originals disk (or the ingest aborted), so nothing else reads it; a
            // long-running worker would otherwise leak one file per video share.
            @unlink($tmp);
        }
    }

    /**
     * Stream the media to a temp file. yt-dlp paths are used as-is; remote URLs
     * (short-lived, adapter-resolved CDN links — the ingestion layer T-012/T-016
     * owns URL canonicalization/SSRF-vetting) are streamed in chunks and aborted
     * the moment the byte cap is crossed, so a lying/absent Content-Length can
     * never balloon temp disk.
     */
    private function toTempFile(FetchedMedia $media): string
    {
        if ($media->localPath !== null) {
            return $media->localPath; // yt-dlp already wrote a local file
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'dl_');
        $cap = (int) config('media.max_download_bytes');

        $body = Http::timeout(300)->withOptions(['stream' => true])
            ->get((string) $media->url)
            ->toPsrResponse()
            ->getBody();

        $out = fopen($tmp, 'wb');
        $written = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(1_048_576);
                if ($chunk === '') {
                    break;
                }
                $written += strlen($chunk);
                if ($written > $cap) {
                    throw new MediaTooLarge("Streamed body exceeded {$cap} bytes.");
                }
                fwrite($out, $chunk);
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
        }

        return $tmp;
    }

    private function extensionFor(?string $mime): string
    {
        return match ($mime) {
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-matroska' => 'mkv',
            default => 'mp4',
        };
    }

    protected function failureCode(): string
    {
        return 'download_failed';
    }
}
