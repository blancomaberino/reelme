<?php

namespace Database\Factories;

use App\Enums\OfferDiscountType;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\Place;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    /**
     * A draft percent offer inside a valid window — the state an operator's
     * first save produces. Every "live" case is an explicit state below, so a
     * test that means `active` has to say so.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'place_id' => Place::factory(),
            'created_by_user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'discount_type' => OfferDiscountType::Percent,
            'discount_value' => fake()->numberBetween(5, 50),
            'terms' => fake()->optional()->sentence(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'quota_total' => null,
            'quota_per_user' => 1,
            'quota_per_day' => null,
            'redemptions_count' => 0,
            'influencer_share_bps' => 5000,
            'status' => OfferStatus::Draft,
        ];
    }

    /** Live: active, window open. */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => OfferStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);
    }

    /**
     * Window closed yesterday but `status` still reads `active` — the exact
     * drift the `active()` scope exists to survive (nothing rewrites the column
     * when a window lapses).
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => OfferStatus::Active,
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subDay(),
        ]);
    }

    /** Window opens next week — advertisable, not yet redeemable. */
    public function upcoming(): static
    {
        return $this->state(fn () => [
            'status' => OfferStatus::Active,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeeks(5),
        ]);
    }

    /** Live and in-window, but the lifetime cap is used up. */
    public function quotaExhausted(int $quota = 10): static
    {
        return $this->active()->state(fn () => [
            'quota_total' => $quota,
            'redemptions_count' => $quota,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => OfferStatus::Paused]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => OfferStatus::Archived]);
    }

    public function fixedAmount(int $minorUnits = 500): static
    {
        return $this->state(fn () => [
            'discount_type' => OfferDiscountType::FixedAmount,
            'discount_value' => $minorUnits,
        ]);
    }

    public function freeItem(int $count = 1): static
    {
        return $this->state(fn () => [
            'discount_type' => OfferDiscountType::FreeItem,
            'discount_value' => $count,
        ]);
    }
}
