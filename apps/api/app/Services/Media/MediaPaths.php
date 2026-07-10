<?php

namespace App\Services\Media;

/**
 * Canonical storage paths for a share's media (T-017 relies on these exactly).
 * Originals live on the originals disk; frames/thumb/audio on the media disk.
 */
final class MediaPaths
{
    /** Transient source video / screen recording (originals disk). */
    public static function original(string $shareId, string $sha256, string $ext): string
    {
        $ext = ltrim($ext, '.');

        return "media/{$shareId}/original/{$sha256}.{$ext}";
    }

    /** A scene-detection keyframe (media disk). */
    public static function frame(string $shareId, int $index, int $ms): string
    {
        return "media/{$shareId}/frames/frame_{$index}_{$ms}.jpg";
    }

    /** Poster thumbnail (media disk). */
    public static function thumbnail(string $shareId): string
    {
        return "media/{$shareId}/thumb.jpg";
    }

    /** Extracted audio for transcription (media disk). */
    public static function audio(string $shareId): string
    {
        return "media/{$shareId}/audio.wav";
    }
}
