<?php

namespace Database\Factories;

use App\Models\Place;
use App\Models\PlaceEdit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceEdit>
 */
class PlaceEditFactory extends Factory
{
    protected $model = PlaceEdit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'place_id' => Place::factory(),
            'user_id' => null,
            'origin' => PlaceEdit::ORIGIN_MANUAL,
            'changes' => [
                'phone' => ['from' => null, 'to' => '+34 600 000 000'],
            ],
            'note' => null,
        ];
    }
}
