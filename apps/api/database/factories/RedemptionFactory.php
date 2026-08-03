<?php

namespace Database\Factories;

use App\Enums\RedemptionStatus;
use App\Models\Offer;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Redemptions\RedemptionCode;
use App\Services\Redemptions\RedemptionIssuer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
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
            // Signed against id 0: a factory row is not meant to survive a real
            // QR scan, and tests that care use the issuer, which signs properly.
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
