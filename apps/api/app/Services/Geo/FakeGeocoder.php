<?php

namespace App\Services\Geo;

/**
 * In-memory Geocoder for tests and local dev without a Google key. Seed known
 * names with `seed()`; unknown names resolve to null (a legitimate miss). Records
 * every lookup so tests can assert call counts / arguments.
 */
class FakeGeocoder implements BusinessDetailProvider, Geocoder
{
    /** @var array<string, GeocodeResult> */
    private array $seeded = [];

    /** @var array<string, BusinessDetails> */
    private array $seededBusiness = [];

    /** @var list<array{name: string, hints: GeoHints}> */
    public array $calls = [];

    /** @var list<string> */
    public array $businessCalls = [];

    public function seed(string $name, GeocodeResult $result): self
    {
        $this->seeded[mb_strtolower($name)] = $result;

        return $this;
    }

    public function seedBusinessDetails(string $googlePlaceId, BusinessDetails $details): self
    {
        $this->seededBusiness[$googlePlaceId] = $details;

        return $this;
    }

    public function findPlace(string $name, GeoHints $hints): ?GeocodeResult
    {
        $this->calls[] = ['name' => $name, 'hints' => $hints];

        return $this->seeded[mb_strtolower($name)] ?? null;
    }

    public function businessDetails(string $googlePlaceId): ?BusinessDetails
    {
        $this->businessCalls[] = $googlePlaceId;

        return $this->seededBusiness[$googlePlaceId] ?? null;
    }
}
