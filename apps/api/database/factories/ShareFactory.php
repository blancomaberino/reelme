<?php

namespace Database\Factories;

use App\Enums\ShareStatus;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Share>
 */
class ShareFactory extends Factory
{
    protected $model = Share::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_post_id' => SourcePost::factory(),
            'status' => ShareStatus::Pending,
            'shared_via' => fake()->randomElement(['share_sheet', 'paste_url', 'manual']),
        ];
    }

    public function review(): static
    {
        return $this->state(fn () => ['status' => ShareStatus::Review]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => ShareStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ShareStatus::Failed,
            'failure_reason' => fake()->sentence(),
        ]);
    }
}
