<?php

namespace App\Http\Resources\Concerns;

use App\Models\Place;
use App\Services\Map\MapViewport;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * ONE clock reading per request, shared by every row of the response.
 *
 * `$request->attributes` is Symfony's per-request bag, so this is memoized for
 * exactly the lifetime that matters and needs no static, no singleton and no
 * reset between tests.
 *
 * It is a trait rather than a line in one resource because the invariant is not
 * about a resource: `open_state` is now emitted by the place detail, by every
 * listing row, and by every map pin, and a bare `now()` per row lets a page
 * served across a minute boundary report two venues with identical hours as one
 * open and one closed. {@see Place::openState()} takes the instant
 * as a REQUIRED argument so that a caller has to say which clock it used; this
 * is the answer they give.
 *
 * {@see MapViewport} does the same thing by hoisting one
 * `now()` per response — it builds arrays rather than resources, so it has no
 * `$request` to hang this on at the point of use.
 */
trait ResolvesRequestInstant
{
    protected static function instant(Request $request): DateTimeInterface
    {
        $at = $request->attributes->get('reelmap.now');

        if (! $at instanceof DateTimeInterface) {
            $at = now();
            $request->attributes->set('reelmap.now', $at);
        }

        return $at;
    }
}
