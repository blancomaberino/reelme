<?php

namespace Database\Factories;

use App\Enums\TakedownRequesterRole;
use App\Enums\TakedownStatus;
use App\Models\SourcePost;
use App\Models\TakedownRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TakedownRequest>
 */
class TakedownRequestFactory extends Factory
{
    protected $model = TakedownRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_name' => fake()->name(),
            'requester_email' => fake()->safeEmail(),
            'requester_role' => TakedownRequesterRole::Rightsholder,
            'source_post_id' => null,
            'target_url' => null,
            'notes' => null,
            'status' => TakedownStatus::Received,
        ];
    }

    public function forPost(SourcePost $post): static
    {
        return $this->state(fn () => [
            'source_post_id' => $post->id,
            'target_url' => $post->url,
        ]);
    }
}
