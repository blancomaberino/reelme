<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\Influencer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Influencer>
 */
class InfluencerFactory extends Factory
{
    protected $model = Influencer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform' => fake()->randomElement(Platform::cases()),
            'handle' => Str::lower(fake()->unique()->userName()),
            'display_name' => fake()->name(),
            'avatar_url' => fake()->imageUrl(),
        ];
    }
}
