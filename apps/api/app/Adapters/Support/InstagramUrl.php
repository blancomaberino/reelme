<?php

namespace App\Adapters\Support;

/**
 * Instagram permalink parsing shared by the keyless and authed Instagram
 * adapters (T-013/T-015). Both need the same shortcode/external-id derivation;
 * this keeps that single-sourced instead of copied per adapter.
 */
final class InstagramUrl
{
    /** The shortcode from /p/, /reel/, /reels/, /tv/ URLs, else null. */
    public static function shortcode(string $url): ?string
    {
        return preg_match('#/(?:p|reel|reels|tv)/([A-Za-z0-9_-]+)#', $url, $m) === 1 ? $m[1] : null;
    }

    /** The shortcode when present, else a stable short hash of the URL. */
    public static function externalId(string $url): string
    {
        return self::shortcode($url) ?? substr(sha1($url), 0, 24);
    }
}
