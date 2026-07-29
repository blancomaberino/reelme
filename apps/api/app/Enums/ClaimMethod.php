<?php

namespace App\Enums;

/**
 * How an influencer identity was proven (T-038, 06 §5.1): an OAuth handle match
 * against a linked platform account (automatic, best-effort), a one-time code
 * placed in the profile bio (the reliable primary path), or a manual admin
 * assignment from the Filament panel (a moderator vouching directly).
 */
enum ClaimMethod: string
{
    case Oauth = 'oauth';
    case BioCode = 'bio_code';
    case Admin = 'admin';
}
