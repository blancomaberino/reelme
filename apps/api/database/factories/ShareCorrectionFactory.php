<?php

namespace Database\Factories;

use App\Models\Share;
use App\Models\ShareCorrection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShareCorrection>
 */
class ShareCorrectionFactory extends Factory
{
    protected $model = ShareCorrection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'share_id' => Share::factory(),
            'field_path' => 'place.name',
            'model_value' => fake()->company(),
            'user_value' => fake()->company(),
        ];
    }
}
