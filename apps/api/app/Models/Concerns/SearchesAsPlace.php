<?php

namespace App\Models\Concerns;

use App\Enums\PlaceStatus;
use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Searchable;

/**
 * The Scout projection (T-106), extracted from `Place`.
 *
 * What a place looks like *to the search index* is a different question from
 * how it persists, and it changes for different reasons — a new facet, a new
 * localized tag field. Keeping it here means a change to the index shape does
 * not touch the model at all.
 *
 * Pulls in {@see Searchable} itself, so a using model gets the whole search
 * capability from one `use`.
 */
trait SearchesAsPlace
{
    use Searchable;

    /**
     * Same visibility rule as the public read surfaces (map/browse): pending +
     * active places are searchable — the documented deviation from "active
     * only", since a first auto-publish stays pending (02 §3.8) and would
     * otherwise be undiscoverable. Merged tombstones drop out on save.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->merged_into_place_id === null
            && in_array($this->status, [PlaceStatus::Pending, PlaceStatus::Active], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        // Bulk import selects lat/lng as aliases (makeAllSearchableUsing);
        // a single-model sync falls back to the coordinate query.
        $lat = $this->getAttribute('lat');
        $lng = $this->getAttribute('lng');
        if ($lat === null || $lng === null) {
            ['lat' => $lat, 'lng' => $lng] = $this->coordinates();
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'normalized_name' => $this->normalized_name,
            'slug' => $this->slug,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'cuisine_primary' => $this->cuisine_primary,
            'price_range' => $this->price_range,
            // Slugs drive filtering; `tag_names` (English + every localized label)
            // make the place findable by a tag typed in any language (ADR-084 #3).
            'tags' => $this->tags->pluck('slug')->all(),
            'tag_names' => $this->tags->flatMap->searchableNames()->unique()->values()->all(),
            'shares_count' => (int) $this->shares_count,
            '_geo' => ['lat' => (float) $lat, 'lng' => (float) $lng],
        ];
    }

    /**
     * @param  Builder<Place>  $query
     * @return Builder<Place>
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query
            ->select('places.*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
            ->with('tags');
    }
}
