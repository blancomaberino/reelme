<?php

namespace App\Services\Influencers;

use App\Enums\Platform;
use App\Services\Media\Instagram\InstagramWebClient;

/**
 * Fetches an influencer's public profile bio so the claim flow can scan it for a
 * one-time verification code (T-038). Best-effort by design (06 §5.1): only
 * Instagram has a wired profile transport today, and every fetch can fail
 * (expired session cookie, rate limit, private/dead account). A `null` return
 * means "couldn't read the bio right now" — the caller surfaces a transient
 * "try again" rather than burning the token — NOT "the code isn't there".
 */
class ProfileBioFetcher
{
    public function __construct(private readonly InstagramWebClient $instagram) {}

    /**
     * The profile bio text for `$handle` on `$platform`, or null when it can't be
     * read (unsupported platform, transport failure, or a bioless account).
     */
    public function fetchProfileBio(Platform $platform, string $handle): ?string
    {
        $bio = match ($platform) {
            Platform::Instagram => $this->instagramBio($handle),
            // X / TikTok / YouTube have no profile transport yet — treated as
            // "unavailable", so the flow degrades to a retry, not a hard failure.
            default => null,
        };

        return ($bio !== null && trim($bio) !== '') ? $bio : null;
    }

    private function instagramBio(string $handle): ?string
    {
        $user = $this->instagram->profile($handle);
        $bio = $user['biography'] ?? null;

        return is_string($bio) ? $bio : null;
    }
}
