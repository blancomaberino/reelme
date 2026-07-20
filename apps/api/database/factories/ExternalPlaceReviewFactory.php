<?php

namespace Database\Factories;

use App\Models\ExternalPlaceReview;
use App\Models\Place;
use App\Services\Reviews\Drivers\TrustpilotReviewSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalPlaceReview>
 */
class ExternalPlaceReviewFactory extends Factory
{
    protected $model = ExternalPlaceReview::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'place_id' => Place::factory(),
            'source' => TrustpilotReviewSource::ID,
            'rating' => fake()->randomFloat(1, 1, 5),
            'review_count' => fake()->numberBetween(1, 5000),
            'url' => 'https://www.trustpilot.com/review/example.com',
            'snippets_json' => [
                ['author' => 'Ana', 'rating' => 5, 'text' => 'Loved it', 'relative_time' => null, 'profile_photo_url' => null],
            ],
            'synced_at' => now(),
        ];
    }

    /** Age the cached row past a given number of days (for staleness tests). */
    public function syncedDaysAgo(int $days): static
    {
        return $this->state(fn () => ['synced_at' => now()->subDays($days)]);
    }
}
