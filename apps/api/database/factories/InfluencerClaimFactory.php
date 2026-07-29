<?php

namespace Database\Factories;

use App\Enums\ClaimMethod;
use App\Enums\ClaimStatus;
use App\Models\Influencer;
use App\Models\InfluencerClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            'token' => 'reelmap-verify-'.Str::lower(Str::random(8)),
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
