<?php

namespace App\Services\Places;

use App\Models\Place;
use App\Models\PlaceSource;

/**
 * The result of running the dedup decision tree (04 §6) for one share. The job
 * maps each type onto a share transition: attached/created → continue to publish;
 * ambiguous → review with candidates; geocode_failed → review.
 */
final class ResolutionOutcome
{
    public const ATTACHED = 'attached';

    public const CREATED = 'created';

    public const AMBIGUOUS = 'ambiguous';

    public const GEOCODE_FAILED = 'geocode_failed';

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function __construct(
        public readonly string $type,
        public readonly ?Place $place = null,
        public readonly ?PlaceSource $source = null,
        public readonly array $candidates = [],
    ) {}

    public static function attached(Place $place, PlaceSource $source): self
    {
        return new self(self::ATTACHED, $place, $source);
    }

    public static function created(Place $place, PlaceSource $source): self
    {
        return new self(self::CREATED, $place, $source);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self(self::AMBIGUOUS, candidates: $candidates);
    }

    public static function geocodeFailed(): self
    {
        return new self(self::GEOCODE_FAILED);
    }

    public function resolved(): bool
    {
        return $this->type === self::ATTACHED || $this->type === self::CREATED;
    }
}
