<?php

namespace App\Http\Requests\Concerns;

/**
 * The `?near=lat,lng` viewer position, parsed identically on every surface that
 * accepts one: `/places` (T-030), `/offers` (T-042) and `/map/places` (T-156).
 *
 * It lives in ONE place because the two copies it replaces had already drifted
 * inside a single change: the map got an unconditional merge and server-side
 * rounding while the listing kept neither, so `?near=1,2,3&nearLat=10&nearLng=20`
 * returned a 422 from one endpoint and a confident 200 measured from (10, 20)
 * from the other — for the same input, against the same column. A parse rule
 * that only applies on the endpoint someone happened to be editing is the shape
 * CLAUDE.md's "a new rule needs every writer" describes.
 *
 * A user of this trait must:
 *  - call {@see self::mergeNearPoint()} from `prepareForValidation()`, and
 *  - spread {@see self::nearRules()} into `rules()` and
 *    {@see self::nearMessages()} into `messages()`.
 */
trait ParsesNearPoint
{
    /**
     * Decimal places kept on the viewer's position — ~11 m at this latitude.
     * Mirrored by `NEAR_PRECISION` in the mobile client's `nearParam()`, and by
     * the figure the privacy policy quotes.
     */
    public const NEAR_PRECISION = 4;

    /**
     * Splits `near` into the derived `nearLat`/`nearLng`.
     *
     * `is_string`, not `!== null`: this runs BEFORE the rules, so `?near[]=1`
     * would cast an array to string — a PHP warning Laravel promotes to a 500
     * on a public route (found by review on T-042).
     *
     * The merge is UNCONDITIONAL, and that is the load-bearing part: these two
     * keys are DERIVED, so a caller must never be able to supply them. Merging
     * only on a successful split left `?near=1,2,3&nearLat=10&nearLng=20`
     * passing every rule and measuring every distance from (10, 20) while
     * `near` said something else — a 200 the caller has no way to doubt.
     * Writing null on a failed split makes `required_with:near` fire instead,
     * which is the 422 that input earns.
     */
    protected function mergeNearPoint(): void
    {
        $near = $this->query('near');
        $parts = is_string($near) ? array_map('trim', explode(',', $near)) : [];

        $this->merge(count($parts) === 2
            ? ['nearLat' => $parts[0], 'nearLng' => $parts[1]]
            : ['nearLat' => null, 'nearLng' => null]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function nearRules(): array
    {
        return [
            'near' => ['nullable', 'string'],
            // `nullable` is what is load-bearing here — NOT its position in the
            // list. The merge above always writes these two keys, so on a
            // request with no `near` at all they are PRESENT and null, and
            // `numeric` alone would 422 every ordinary request.
            //
            // Ordering is inert, and a comment claiming otherwise stood here
            // until review checked it: `RequiredWith` is in `Validator::
            // $implicitRules`, and `isNotNullIfMarkedAsNullable()` short-circuits
            // for any implicit rule, so `required_with` fires on a null value
            // wherever `nullable` sits.
            'nearLat' => ['nullable', 'required_with:near', 'numeric', 'between:-90,90'],
            'nearLng' => ['nullable', 'required_with:near', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function nearMessages(): array
    {
        return [
            'nearLat.required_with' => 'near must be "lat,lng".',
            'nearLng.required_with' => 'near must be "lat,lng".',
        ];
    }

    /**
     * The viewer's position, or null when they did not share one.
     *
     * Null is the load-bearing case: `distance_m` is omitted from the payload
     * rather than defaulted, because 0 is a real distance ("you are standing in
     * it") and a client cannot tell a default from the truth.
     *
     * A malformed `near` never reaches here — the unconditional merge nulls the
     * derived fields, so `required_with:near` 422s first. The ONE input accepted
     * silently is `?near=`, because Laravel's ConvertEmptyStringsToNull turns it
     * into an absent parameter before any of this runs: it means "no position",
     * not "a broken position", and the mobile client omits the parameter
     * entirely when it has no fix.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function nearPoint(): ?array
    {
        if ($this->validated('nearLat') === null) {
            return null;
        }

        // Truncated to ~11 m, SERVER-side, and that is the point: the mobile
        // client already rounds, but a client-side rounding is a promise, not a
        // control — any other caller of these public endpoints can send nine
        // decimals straight into the access log. Enforcing the floor here is
        // what makes the privacy policy's "rounded to about 10 metres" a
        // statement about the system rather than about one app.
        //
        // Not coarser: the map sheet renders whole metres under a kilometre, so
        // a 111 m floor (3 decimals) would visibly wobble a "450 m" label.
        return [
            'lat' => round((float) $this->validated('nearLat'), self::NEAR_PRECISION),
            'lng' => round((float) $this->validated('nearLng'), self::NEAR_PRECISION),
        ];
    }
}
