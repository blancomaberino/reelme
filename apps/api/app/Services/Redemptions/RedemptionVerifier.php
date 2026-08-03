<?php

namespace App\Services\Redemptions;

use App\Enums\RedemptionStatus;
use App\Events\RedemptionVerified;
use App\Exceptions\RedemptionInvalid;
use App\Models\Place;
use App\Models\Redemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Marks a code redeemed — exactly once (T-043, 06 §3).
 *
 * This is the task's whole reason for existing. Staff double-tap, retry over
 * flaky café wifi, and two devices scan the same QR seconds apart. Every one of
 * those must produce ONE redeemed row and ONE ledger entry (T-044), because the
 * row is what a restaurant is billed for.
 *
 * Three mechanisms stack, and none is sufficient alone:
 *
 * 1. `lockForUpdate()` serialises concurrent verifications of the same code, so
 *    the checks below run against a row nobody else is mid-flight on.
 * 2. The **guarded UPDATE** (`where status = 'issued'`) is the actual guarantee.
 *    A read-then-write without it loses the race on any isolation level that
 *    permits the read to be stale; here, the loser's UPDATE simply matches zero
 *    rows and is told so.
 * 3. A CHECK constraint keeps `status = redeemed` and `redeemed_at` in step, so
 *    even a hand-written UPDATE cannot leave a billable row that cannot say when
 *    it was honoured.
 *
 * A second call from the same staff member REPLAYS rather than errors — 03 §1
 * idempotency. To the person at the till a retry after a timeout is not a
 * failure, and making it one teaches them to re-issue codes instead.
 */
class RedemptionVerifier
{
    public function __construct(
        private readonly RedemptionGuards $guards,
        private readonly RedemptionGeofence $geofence,
    ) {}

    /**
     * @param  string  $code  raw staff input, or a scanned QR payload
     *
     * @throws RedemptionInvalid
     */
    public function verify(
        User $staff,
        string $code,
        Place $place,
        ?float $staffLat = null,
        ?float $staffLng = null,
    ): VerifyResult {
        $this->guards->assertVerifyVelocity($staff);

        $normalized = $this->normalize($code);

        // Shape-checked before any lookup: a malformed string is not a database
        // round trip, and the till cannot be used to enumerate codes by timing.
        if (! RedemptionCode::isWellFormed($normalized)) {
            throw RedemptionInvalid::notFound();
        }

        return DB::transaction(function () use ($staff, $normalized, $place, $staffLat, $staffLng): VerifyResult {
            $redemption = Redemption::query()
                ->where('code', $normalized)
                ->lockForUpdate()
                ->first();

            if ($redemption === null) {
                throw RedemptionInvalid::notFound();
            }

            // Wrong-venue is checked BEFORE the already-redeemed replay: a
            // restaurant must never be shown another venue's redemption details,
            // even a real one they happen to hold the code for.
            if ($redemption->offer?->place_id !== $place->id) {
                throw RedemptionInvalid::wrongPlace();
            }

            if ($redemption->status === RedemptionStatus::Redeemed) {
                return VerifyResult::replay($redemption);
            }

            if ($redemption->status !== RedemptionStatus::Issued) {
                throw RedemptionInvalid::notLive();
            }

            // The clock, not the column: the sweep that writes `expired` runs on
            // a schedule, so a code can be past its window while still reading
            // `issued`. Billing a visit made on a lapsed code is 06 §2.3's
            // "never billable" broken.
            if ($redemption->hasExpired()) {
                throw RedemptionInvalid::expired();
            }

            // Throws when a location IS given and is out of range; a missing one
            // is recorded as unknown and allowed through (see the geofence).
            $geo = $this->geofence->check($place, $staffLat, $staffLng);

            $flipped = Redemption::query()
                ->whereKey($redemption->id)
                ->where('status', RedemptionStatus::Issued)
                ->update([
                    'status' => RedemptionStatus::Redeemed,
                    'redeemed_at' => now(),
                    'redeemed_by_user_id' => $staff->id,
                    'geofence_ok' => $geo['ok'],
                    'geofence_distance_m' => $geo['distance_m'],
                    'updated_at' => now(),
                ]);

            // Zero means someone else won between the lock and here. Under the
            // row lock that should be impossible — which is exactly why it is
            // checked: if it ever fires, the guarantee has been broken and we
            // want an error, not a double-billed restaurant.
            if ($flipped !== 1) {
                throw RedemptionInvalid::alreadyRedeemed();
            }

            $redemption->refresh();

            // Inside the transaction on purpose: T-044's ledger listener must
            // commit with the state flip or not at all. A fee posted for a
            // redemption that rolled back is money invented from nothing.
            RedemptionVerified::dispatch($redemption);

            return VerifyResult::fresh($redemption);
        });
    }

    /**
     * Accept either what was typed or what was scanned.
     *
     * A QR carries a signed payload, so its embedded code is extracted first;
     * anything else is treated as hand-typed and folded through Crockford's
     * confusable substitutions.
     */
    private function normalize(string $code): string
    {
        return RedemptionCode::normalize(RedemptionQr::codeFrom(trim($code)) ?? $code);
    }
}
