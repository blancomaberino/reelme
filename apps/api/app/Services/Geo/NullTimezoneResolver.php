<?php

namespace App\Services\Geo;

/**
 * The {@see TimezoneResolver} used when no provider key is configured (T-155).
 *
 * It resolves nothing, on purpose. The alternatives are all worse: the server's
 * own timezone would be right only for venues that happen to share it, and a
 * fixed offset is wrong for half the year wherever DST applies. Resolving
 * nothing means those places show their hours lines and no status cue — the one
 * honest answer available without the data.
 *
 * Not a test double. Fakes live in `tests/`; this is the production behaviour of
 * an unconfigured install, and dev environments run on it.
 */
class NullTimezoneResolver implements TimezoneResolver
{
    public function resolve(float $lat, float $lng): ?string
    {
        return null;
    }
}
