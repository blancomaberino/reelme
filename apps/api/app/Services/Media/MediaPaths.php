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
        // $ext is the only user-influenceable segment here (siblings use fixed
        // extensions). Lower-case then strip everything but [a-z0-9] so the key is
        // case-stable and a caller that ever derives it from a filename/mime can't
        // smuggle a path separator into it.
        $ext = preg_replace('/[^a-z0-9]+/', '', strtolower(ltrim($ext, '.'))) ?? '';

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
