<?php

namespace App\Enums;

/**
 * Lifecycle of a canonical place (map pin).
 *
 * - Pending: created from a single source, published to the map but unverified.
 * - Active: confirmed by a second independent source or a user.
 * - Merged: folded into another place; `merged_into_place_id` points at the survivor.
 * - Hidden: moderated off every public surface (spam / not a restaurant).
 *
 * Dedup candidate scans consider only Pending + Active (a Merged row is a
 * tombstone; a Hidden row was rejected by an admin and must not attract matches).
 */
enum PlaceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Merged = 'merged';
    case Hidden = 'hidden';

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
