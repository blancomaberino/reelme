<?php

namespace Database\Factories;

use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceSource>
 */
class PlaceSourceFactory extends Factory
{
    protected $model = PlaceSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'place_id' => Place::factory(),
            'source_post_id' => SourcePost::factory(),
            'share_id' => Share::factory(),
            'analysis_run_id' => null,
            'extraction_snapshot_json' => ['place' => ['name' => fake()->company()]],
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}
