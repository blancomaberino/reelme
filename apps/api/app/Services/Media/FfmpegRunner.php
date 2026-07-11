<?php

namespace App\Services\Media;

use App\Services\Media\Data\MediaProbe;
use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper over the ffmpeg/ffprobe binaries via Laravel's Process facade.
 * ffmpeg writes progress to stderr even on success, so success is judged by exit
 * code, never output presence.
 */
class FfmpegRunner
{
    public function __construct(private readonly int $timeout = 600) {}

    /**
     * ffprobe a media file into a MediaProbe (duration, dimensions, audio flag).
     *
     * @throws MediaProcessingException
     */
    public function probe(string $path): MediaProbe
    {
        $result = Process::timeout($this->timeout)->run([
            $this->probeBin(), '-v', 'quiet', '-print_format', 'json',
            '-show_format', '-show_streams', $path,
        ]);

        if (! $result->successful()) {
            throw new MediaProcessingException("ffprobe failed for {$path}: ".$result->errorOutput());
        }

        /** @var array<string, mixed> $json */
        $json = json_decode($result->output(), true) ?: [];
        $streams = $json['streams'] ?? [];

        $video = $this->firstStream($streams, 'video');
        $duration = (float) ($json['format']['duration'] ?? 0.0);

        return new MediaProbe(
            durationMs: (int) round($duration * 1000),
            width: isset($video['width']) ? (int) $video['width'] : null,
            height: isset($video['height']) ? (int) $video['height'] : null,
            hasAudio: $this->firstStream($streams, 'audio') !== null,
        );
    }

    /**
     * Run an ffmpeg command (args after the binary). Returns stderr for callers
     * that parse showinfo. Throws on a non-zero exit.
     *
     * @param  list<string>  $args
     *
     * @throws MediaProcessingException
     */
    public function run(array $args): string
    {
        $result = Process::timeout($this->timeout)->run([$this->ffmpegBin(), '-hide_banner', '-y', ...$args]);

        if (! $result->successful()) {
            throw new MediaProcessingException('ffmpeg failed: '.$result->errorOutput());
        }

        return $result->errorOutput();
    }

    /**
     * @param  array<int, array<string, mixed>>  $streams
     * @return array<string, mixed>|null
     */
    private function firstStream(array $streams, string $type): ?array
    {
        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? null) === $type) {
                return $stream;
            }
        }

        return null;
    }

    private function ffmpegBin(): string
    {
        return (string) config('media.ffmpeg_bin', 'ffmpeg');
    }

    private function probeBin(): string
    {
        return (string) config('media.ffprobe_bin', 'ffprobe');
    }
}
