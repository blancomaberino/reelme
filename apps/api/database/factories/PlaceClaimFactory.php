<?php

namespace Database\Factories;

use App\Enums\ClaimStatus;
use App\Enums\PlaceClaimMethod;
use App\Models\Place;
use App\Models\PlaceClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceClaim>
 */
class PlaceClaimFactory extends Factory
{
    protected $model = PlaceClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'place_id' => Place::factory(),
            'user_id' => User::factory(),
            'method' => PlaceClaimMethod::Document,
            'status' => ClaimStatus::Pending,
            'evidence_json' => null,
            'verified_at' => null,
            'reason' => null,
            'reviewed_by_user_id' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'status' => ClaimStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'insufficient_evidence'): static
    {
        return $this->state(fn () => [
            'status' => ClaimStatus::Rejected,
            'reason' => $reason,
        ]);
    }

    public function phone(): static
    {
        return $this->state(fn () => ['method' => PlaceClaimMethod::Phone]);
    }

    public function website(): static
    {
        return $this->state(fn () => ['method' => PlaceClaimMethod::Website]);
    }
}
