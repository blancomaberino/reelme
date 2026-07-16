<?php

namespace App\Services\Places;

/**
 * A location signal mined from a venue's Instagram profile (T-075). Every field
 * is nullable — a profile yields whatever it has: a full IG *business* account
 * gives direct coords + address, a small account may give only a bio locality
 * and/or its real `full_name`. `name` (from `full_name`) upgrades a bare
 * `@handle` to the real venue name.
 */
final readonly class ProfileLocation
{
    public function __construct(
        public ?string $name = null,
        public ?string $street = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?float $lat = null,
        public ?float $lng = null,
    ) {}

    /** Direct coordinates from a business address (no geocoder call needed). */
    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** Any locality signal that could sharpen a geocode query. */
    public function hasLocality(): bool
    {
        return $this->street !== null || $this->city !== null || $this->region !== null || $this->postalCode !== null;
    }
}
