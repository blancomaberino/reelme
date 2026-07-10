<?php

namespace Database\Factories;

use App\Enums\FetchStatus;
use App\Enums\Platform;
use App\Enums\PostPrivacy;
use App\Models\Influencer;
use App\Models\SourcePost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourcePost>
 */
class SourcePostFactory extends Factory
{
    protected $model = SourcePost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform' => fake()->randomElement(Platform::cases()),
            'external_id' => fake()->unique()->bothify('??########'),
            'url' => fake()->url(),
            'influencer_id' => Influencer::factory(),
            'caption' => fake()->optional()->sentence(),
            'posted_at' => fake()->optional()->dateTimeThisYear(),
            'privacy' => PostPrivacy::Unknown,
            'fetch_status' => FetchStatus::Pending,
        ];
    }

    public function fetched(): static
    {
        return $this->state(fn () => [
            'fetch_status' => FetchStatus::Fetched,
            'fetched_at' => now(),
        ]);
    }
}
