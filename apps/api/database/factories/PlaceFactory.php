<?php

namespace Database\Factories;

use App\Enums\ContactFieldSource;
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

    /** A website a provider (Google) put on the record — backs a `website` claim. */
    public function providerWebsite(string $url = 'https://verified.example'): static
    {
        return $this->state(fn () => ['website' => $url, 'website_source' => ContactFieldSource::Google]);
    }

    /** A website the sharer nominated via the extraction — never backs a claim (SEC-1). */
    public function extractionWebsite(string $url = 'https://attacker.example'): static
    {
        return $this->state(fn () => ['website' => $url, 'website_source' => ContactFieldSource::Extraction]);
    }

    /** A phone a provider (Google) put on the record — backs a `phone` claim. */
    public function providerPhone(string $phone = '+59891238891'): static
    {
        return $this->state(fn () => ['phone' => $phone, 'phone_source' => ContactFieldSource::Google]);
    }

    /** A phone the sharer nominated via the extraction — never backs a claim (SEC-1). */
    public function extractionPhone(string $phone = '+10000000000'): static
    {
        return $this->state(fn () => ['phone' => $phone, 'phone_source' => ContactFieldSource::Extraction]);
    }

    private static function point(float $lat, float $lng): Expression
    {
        return DB::raw(sprintf(
            'ST_MakePoint(%s, %s)::geography',
            number_format($lng, 8, '.', ''),
            number_format($lat, 8, '.', ''),
        ));
    }
}
