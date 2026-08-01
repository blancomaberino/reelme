<?php

namespace App\Enums;

/**
 * How a restaurant operator proved they run a place (T-041, 06 §2.1).
 *
 * Deliberately NOT {@see ClaimMethod}, which belongs to influencer identity
 * claims (T-038) and carries `oauth`/`bio_code`/`admin` — values with no meaning
 * for a venue, as these have none for an identity. See ADR-041.
 *
 * Each case proves control of something the place record already lists, so the
 * evidence is checked against data we hold rather than data the claimant
 * supplies:
 *
 * - Phone: a 6-digit code sent to `places.phone` — the number Google lists for
 *   the business, not one typed by the claimant.
 * - Website: a token published at `/.well-known/reelmap-verify.txt` on the host
 *   of `places.website`. Proves write access to the document root.
 * - Document: business registration or a utility bill, reviewed by an admin in
 *   Filament. The fallback for a place with neither a phone nor a website on
 *   file, which is common for the long tail.
 *
 * `email_domain` and `google_business` from 02 §3.12 are deferred — see ADR-041.
 */
enum PlaceClaimMethod: string
{
    case Phone = 'phone';
    case Website = 'website';
    case Document = 'document';

    /**
     * Can this method settle itself, or does it need a human?
     *
     * Only `document` needs review; the other two are proofs the backend can
     * check, so they verify (or fail) without ever reaching the admin queue.
     */
    public function isAutomatic(): bool
    {
        return $this !== self::Document;
    }
}
