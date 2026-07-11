<?php

namespace App\Enums;

/**
 * Lifecycle of a canonical place (map pin).
 *
 * - Pending: created from a single source, published to the map but unverified.
 * - Active: confirmed by a second independent source or a user.
 * - Merged: folded into another place; `merged_into_place_id` points at the survivor.
 *
 * Dedup candidate scans consider only Pending + Active (a Merged row is a tombstone).
 */
enum PlaceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Merged = 'merged';

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
