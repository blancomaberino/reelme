<?php

namespace Database\Factories;

use App\Enums\ClaimMethod;
use App\Enums\ClaimStatus;
use App\Models\Influencer;
use App\Models\InfluencerClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InfluencerClaim>
 */
class InfluencerClaimFactory extends Factory
{
    protected $model = InfluencerClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'influencer_id' => Influencer::factory(),
            'user_id' => User::factory(),
            'method' => ClaimMethod::BioCode,
            'status' => ClaimStatus::Pending,
            // Same RFC 4648 lower-base32 charset the service issues (no 0/1/8/9).
            'token' => 'reelmap-verify-'.fake()->regexify('[a-z2-7]{8}'),
            'reason' => null,
            'expires_at' => now()->addHours(72),
            'reviewed_by_user_id' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'status' => ClaimStatus::Verified,
            'token' => null,
            'reason' => null,
            'expires_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }
}
