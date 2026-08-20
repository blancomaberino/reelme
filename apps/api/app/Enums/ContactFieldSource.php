<?php

namespace App\Enums;

use App\Models\Concerns\LocksFields;

/**
 * Where a place's `website` / `phone` value came from — recorded on the row at
 * write time so the claim service can tell a provider-sourced contact field from
 * one the sharer typed.
 *
 * This is the load-bearing distinction behind SEC-1 (T-117): a place-claim method
 * "proves control of something the PLACE record already lists", which is only a
 * proof of ownership when the listed value came from a provider the claimant does
 * not control. A website the sharer nominated through the extraction/correction
 * path lets them verify any venue on the map, so only {@see self::Google} may back
 * an automatic (website/phone) claim; everything else routes to a document claim.
 *
 * Deliberately NOT folded into `locked_fields` / {@see LocksFields}:
 * that answers "may the pipeline overwrite this field" (human ownership), a
 * different axis from "is this value provider-verified". A field can be
 * Google-sourced and unlocked (claimable), or Manual-sourced and locked (an admin
 * typed it — locked from the pipeline, but NOT a proof of ownership). One column
 * cannot answer both questions without conflating them.
 */
enum ContactFieldSource: string
{
    /** From Google (geocode / business-details enrichment). The only claim-trusted source. */
    case Google = 'google';

    /** From the LLM extraction — including a reviewer's PATCH /shares correction. Claimant-controlled; untrusted. */
    case Extraction = 'extraction';

    /** A human typed it (a Filament admin edit, or an approved user suggestion). Not provider-verified; untrusted for automatic claims. */
    case Manual = 'manual';

    /** True only when the value came from a provider the claimant cannot control. */
    public function providerVerified(): bool
    {
        return $this === self::Google;
    }
}
