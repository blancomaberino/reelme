<?php

namespace App\Services\Geo;

/**
 * Optional signals that sharpen a geocode lookup: address parts and a lat/lng
 * bias extracted upstream, plus the post language so Google returns names in the
 * right script. All nullable — ResolvePlace (T-023) fills what the extraction
 * gave it and leaves the rest null.
 */
final readonly class GeoHints
{
    public function __construct(
        public ?string $street = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $language = null,
    ) {}

    public function hasBias(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }
}
