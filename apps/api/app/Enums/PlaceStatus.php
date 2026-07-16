<?php

namespace App\Enums;

use App\Services\Places\PlacePublisher;

/**
 * Lifecycle of a canonical place (map pin).
 *
 * - Pending: created from a single source, published to the map but unverified.
 * - Active: confirmed by a second independent source or a user.
 * - Merged: folded into another place; `merged_into_place_id` points at the survivor.
 * - Hidden: moderated off every public surface (spam / not a restaurant).
 * - Removed: auto-tombstoned after its last contributor fully removed it (T-073) —
 *   no published source remains and no list saved it, so it would otherwise
 *   linger as a provenance-less "ghost pin". Reversible: a later re-share of the
 *   same place revives it (see {@see PlacePublisher}).
 *
 * Dedup candidate scans consider only Pending + Active (a Merged row is a
 * tombstone; a Hidden row was rejected by an admin, and a Removed row is an
 * orphaned tombstone — none of the three must attract fuzzy matches).
 */
enum PlaceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Merged = 'merged';
    case Hidden = 'hidden';
    case Removed = 'removed';

    /**
     * Statuses eligible to be matched against during resolution.
     *
     * @return list<string>
     */
    public static function matchable(): array
    {
        return [self::Pending->value, self::Active->value];
    }
}
