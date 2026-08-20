<?php

namespace Database\Factories;

use App\Enums\RedemptionStatus;
use App\Models\Offer;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Redemptions\OfferQuotaCounter;
use App\Services\Redemptions\OfferQuotaReconciler;
use App\Services\Redemptions\RedemptionCode;
use App\Services\Redemptions\RedemptionIssuer;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * Redemption rows, and only rows: this factory deliberately does NOT move
 * `offers.redemptions_count`.
 *
 * That column is a claim {@see OfferQuotaCounter} takes on the issue path
 * (T-127), and a factory that claimed too would double-count every fixture that
 * mixes seeded rows with a real issue. A row written straight into the table IS
 * the out-of-band write {@see OfferQuotaReconciler} is the safety net for. What
 * keeps the counter from being "non-zero only because a factory said so" is that
 * the enforcement tests (tests/Feature/Monetization/OfferQuotaCounterTest.php)
 * issue over HTTP.
 *
 * So a fixture that wants the row AND the slot it holds has to take the slot
 * too, through the counter: {@see RedemptionFactory::holdingSlot()} is that
 * pairing, and any test whose subject is the RELEASE path needs it. Several
 * fixtures took the row alone and quietly ran the expiry sweep down
 * `release()`'s drift branch for a phase — green the whole time, because a
 * refused release and an honoured one leave the same 0 behind.
 *
 * @extends Factory<Redemption>
 */
class RedemptionFactory extends Factory
{
    protected $model = Redemption::class;

    /**
     * A live, issued code — the state every other one is reached from.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = RedemptionCode::generate();

        return [
            'offer_id' => Offer::factory(),
            'user_id' => User::factory(),
            'code' => $code,
            // An UNSIGNED placeholder — the literal string, no HMAC. A factory
            // row cannot pass RedemptionQr::verify(), which is deliberate: a
            // test about QR signing must go through the issuer, which signs
            // over the real row id.
            'qr_payload' => 'v1.'.$code.'.factory',
            'status' => RedemptionStatus::Issued,
            'issued_at' => now(),
            'expires_at' => now()->addHours(RedemptionIssuer::TTL_HOURS),
            'redeemed_at' => null,
            'redeemed_by_user_id' => null,
            'attributed_influencer_id' => null,
            'attributed_share_id' => null,
            'fee_amount' => null,
            'currency' => null,
        ];
    }

    /**
     * The row AND the slot it holds, claimed through the production writer —
     * the pair a real issue always leaves behind. Composes with every state
     * below: `->overdue()->holdingSlot()` is a lapsed code still holding one.
     *
     * Opt-in, because the default has to stay non-claiming: a bare row is the
     * out-of-band write the reconciler's drift fixtures are built from, and
     * claiming here would double-count any fixture that also issues for real.
     */
    public function holdingSlot(): static
    {
        return $this->afterCreating(function (Redemption $redemption): void {
            if (! app(OfferQuotaCounter::class)->claim((int) $redemption->offer_id)) {
                throw new RuntimeException(
                    "Offer {$redemption->offer_id} had no slot left for this fixture to hold.",
                );
            }
        });
    }

    /**
     * Honoured. `redeemed_at` is set with it — the CHECK constraint requires the
     * two to agree, so there is no way to build a half-redeemed row by accident.
     */
    public function redeemed(?User $staff = null): static
    {
        return $this->state(fn () => [
            'status' => RedemptionStatus::Redeemed,
            'redeemed_at' => now(),
            'redeemed_by_user_id' => $staff === null ? User::factory() : $staff->id,
        ]);
    }

    /**
     * Window closed while the column still reads `issued` — the exact drift the
     * expiry sweep exists to clean up, and the state a verify must refuse.
     */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => RedemptionStatus::Issued,
            'issued_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => RedemptionStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function void(): static
    {
        return $this->state(fn () => ['status' => RedemptionStatus::Void]);
    }

    /** A specific code, for tests that verify by string. */
    public function withCode(string $code): static
    {
        return $this->state(fn () => ['code' => $code, 'qr_payload' => 'v1.'.$code.'.factory']);
    }
}
