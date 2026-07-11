<?php

namespace App\Services\Media;

use App\Services\Media\Data\ExtractedFrame;
use App\Services\Media\Data\MediaProbe;
use App\Services\Media\Data\ProcessedMedia;

/**
 * Derives the AI-analysis inputs from an original clip (04 §1 PrepareMedia):
 * 16 kHz mono WAV, scene-detected keyframes (uniform-sampling fallback, hard cap
 * 12), and a poster thumbnail. Operates entirely on local temp paths — the job
 * owns download/upload.
 */
class MediaProcessor
{
    public function __construct(private readonly FfmpegRunner $ffmpeg) {}

    public function process(string $inPath, string $outDir, MediaProbe $probe): ProcessedMedia
    {
        $audio = $probe->hasAudio ? $this->extractAudio($inPath, "{$outDir}/audio.wav") : null;
        $frames = $this->extractKeyframes($inPath, $outDir, $probe->durationMs);
        $thumbnail = $this->makeThumbnail($frames, "{$outDir}/thumb.jpg");

        return new ProcessedMedia($audio, $frames, $thumbnail);
    }

    /** 16 kHz mono PCM WAV for transcription (04 §1). */
    private function extractAudio(string $inPath, string $outPath): string
    {
        $this->ffmpeg->run([
            '-i', $inPath, '-vn', '-ac', '1',
            '-ar', (string) config('media.audio_sample_rate', 16000),
            '-c:a', 'pcm_s16le', $outPath,
        ]);

        return $outPath;
    }

    /**
     * Scene-detected keyframes, falling back to uniform sampling when the shot is
     * too static to yield enough scene changes. Result is chronological with a
     * stable 0-based index (the frame_refs contract).
     *
     * @return list<ExtractedFrame>
     */
    private function extractKeyframes(string $inPath, string $outDir, int $durationMs): array
    {
        $edge = (int) config('media.keyframe_longest_edge', 1024);
        $threshold = (float) config('media.scene_threshold', 0.3);
        $max = (int) config('media.max_keyframes', 12);
        $min = (int) config('media.min_scene_frames', 4);

        $sceneDir = "{$outDir}/scene";
        @mkdir($sceneDir, 0755, true);

        $stderr = $this->ffmpeg->run([
            '-i', $inPath,
            '-vf', "select='gt(scene,{$threshold})',showinfo,scale='min({$edge},iw)':-2",
            '-vsync', 'vfr', '-q:v', '3', "{$sceneDir}/frame_%03d.jpg",
        ]);

        $files = glob("{$sceneDir}/frame_*.jpg") ?: [];
        sort($files); // chronological — ffmpeg numbers in selection order

        if (count($files) < $min) {
            $this->cleanDir($sceneDir);

            return $this->uniformSample($inPath, $outDir, $durationMs, $edge);
        }

        $ptsTimes = $this->parseShowinfoPts($stderr);
        $files = array_slice($files, 0, $max); // enforce the 12-cap in PHP

        $frames = [];
        foreach ($files as $i => $file) {
            $ms = isset($ptsTimes[$i])
                ? (int) round($ptsTimes[$i] * 1000)
                : (int) round($durationMs * ($i + 1) / (count($files) + 1));
            $frames[] = $this->placeFrame($file, $outDir, $i, $ms);
        }

        return $frames;
    }

    /**
     * Even samples across the clip (fallback when scene detection is too sparse).
     *
     * @return list<ExtractedFrame>
     */
    private function uniformSample(string $inPath, string $outDir, int $durationMs, int $edge): array
    {
        $n = min((int) config('media.max_keyframes', 12), 8);
        $n = max(1, $n);

        $frames = [];
        for ($i = 0; $i < $n; $i++) {
            $ms = (int) round($durationMs * ($i + 1) / ($n + 1));
            $path = "{$outDir}/frame_{$i}_{$ms}.jpg";
            $this->ffmpeg->run([
                '-ss', sprintf('%.3f', $ms / 1000), '-i', $inPath,
                '-frames:v', '1', '-vf', "scale='min({$edge},iw)':-2", '-q:v', '3', $path,
            ]);
            $frames[] = new ExtractedFrame($i, $ms, $path);
        }

        return $frames;
    }

    /**
     * Sharpest of the first 3 frames (JPEG byte size ≈ retained detail), at 640 px.
     *
     * @param  list<ExtractedFrame>  $frames
     */
    private function makeThumbnail(array $frames, string $outPath): string
    {
        if ($frames === []) {
            throw new MediaProcessingException('No keyframes produced; cannot build a thumbnail.');
        }

        $candidates = array_slice($frames, 0, 3);
        usort($candidates, fn (ExtractedFrame $a, ExtractedFrame $b): int => filesize($b->path) <=> filesize($a->path));
        $edge = (int) config('media.thumbnail_edge', 640);

        $this->ffmpeg->run([
            '-i', $candidates[0]->path,
            '-vf', "scale='min({$edge},iw)':-2", '-q:v', '3', $outPath,
        ]);

        return $outPath;
    }

    private function placeFrame(string $sceneFile, string $outDir, int $index, int $ms): ExtractedFrame
    {
        $dest = "{$outDir}/frame_{$index}_{$ms}.jpg";
        rename($sceneFile, $dest);

        return new ExtractedFrame($index, $ms, $dest);
    }

    /**
     * @return list<float>
     */
    private function parseShowinfoPts(string $stderr): array
    {
        preg_match_all('/pts_time:([0-9.]+)/', $stderr, $matches);

        return array_map('floatval', $matches[1]);
    }

    private function cleanDir(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
