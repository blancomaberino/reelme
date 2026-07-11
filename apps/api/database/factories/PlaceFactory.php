<?php

namespace Database\Factories;

use App\Enums\PlaceStatus;
use App\Models\Place;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Place>
 */
class PlaceFactory extends Factory
{
    protected $model = Place::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lat = fake()->randomFloat(6, -60, 60);
        $lng = fake()->randomFloat(6, -120, 120);

        return [
            'name' => fake()->company(),
            'city' => fake()->city(),
            'country_code' => fake()->countryCode(),
            'status' => PlaceStatus::Pending,
            'location' => self::point($lat, $lng),
        ];
    }

    /** Pin the place at exact coordinates (for distance assertions). */
    public function atPoint(float $lat, float $lng): static
    {
        return $this->state(fn () => ['location' => self::point($lat, $lng)]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => PlaceStatus::Active]);
    }

    public function withGooglePlaceId(string $id): static
    {
        return $this->state(fn () => ['google_place_id' => $id]);
    }

    private static function point(float $lat, float $lng): Expression
    {
        return DB::raw(sprintf('ST_MakePoint(%.8f, %.8f)::geography', $lng, $lat));
    }
}
