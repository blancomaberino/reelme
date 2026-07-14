<?php

namespace Database\Factories;

use App\Models\Place;
use App\Models\User;
use App\Models\UserPlaceTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPlaceTag>
 */
class UserPlaceTagFactory extends Factory
{
    protected $model = UserPlaceTag::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'place_id' => Place::factory(),
            'label' => fake()->words(2, true),
        ];
    }
}
