<?php

namespace App\Models;

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

/**
 * A deduplicated place — one map pin (02 §3.8). `location` is PostGIS
 * `geography(Point,4326)`; it is never a plain Eloquent attribute (reads return
 * WKB), so set it via {@see setPoint()} and read coordinates via {@see coordinates()}.
 * `normalized_name` (accent-folded, suffix-stripped) and `slug` are maintained
 * on save for trigram matching and stable URLs.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $normalized_name
 * @property string|null $city
 * @property string $country_code
 * @property string|null $google_place_id
 * @property PlaceStatus $status
 * @property int|null $merged_into_place_id
 * @property int $shares_count
 * @property numeric-string|null $google_rating
 * @property int|null $google_rating_count
 * @property array<int, array<string, mixed>>|null $google_reviews_json
 * @property int|null $reviews_count
 * @property float|numeric-string|null $reviews_avg_rating
 */
class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use HasFactory;

    use Searchable;

    // Written by the resolver/merger only; `location` is set via setPoint(), not
    // mass-assignment (it carries a raw SQL expression, not a scalar).
    protected $fillable = [
        'name', 'slug', 'address_line1', 'address_line2', 'city', 'region',
        'postal_code', 'country_code', 'google_place_id', 'cuisine_primary',
        'price_range', 'phone', 'website', 'opening_hours_json', 'status',
        'merged_into_place_id', 'shares_count', 'avg_extraction_confidence',
        'normalized_name', 'google_rating', 'google_rating_count', 'google_reviews_json',
        'google_reviews_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PlaceStatus::class,
            'opening_hours_json' => 'array',
            'price_range' => 'integer',
            'shares_count' => 'integer',
            'avg_extraction_confidence' => 'decimal:3',
            'google_rating' => 'decimal:1',
            'google_rating_count' => 'integer',
            'google_reviews_json' => 'array',
            'google_reviews_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Maintain the derived matching/URL columns (the "observer" of 02 §3.8).
        static::saving(function (Place $place): void {
            $attributes = $place->getAttributes();
            $name = (string) $place->name;

            if ($place->isDirty('name') || ! array_key_exists('normalized_name', $attributes)) {
                $place->normalized_name = self::normalizeName($name);
            }
            if ($name !== '' && ($attributes['slug'] ?? '') === '') {
                $place->slug = self::makeSlug($name);
            }
        });
    }

    /**
     * Places visible on public read surfaces (map, browse index): pending +
     * active — a first auto-publish is on the map immediately (02 §3.8, the
     * documented deviation from "active only") — never merged tombstones.
     *
     * @param  Builder<Place>  $query
     */
    protected function scopePubliclyVisible(Builder $query): void
    {
        $query->whereIn('status', PlaceStatus::matchable())
            ->whereNull('merged_into_place_id');
    }

    /**
     * Places carrying any of the given tag slugs. The place_tag pivot lands in
     * T-031 — until it exists this is a validated no-op (schema-guarded), so
     * both the map and the browse index accept tags[] today.
     *
     * @param  Builder<Place>  $query
     * @param  list<string>  $slugs
     */
    protected function scopeAnyTagSlug(Builder $query, array $slugs): void
    {
        if ($slugs === [] || ! Schema::hasTable('place_tag')) {
            return;
        }

        $query->whereExists(fn ($sub) => $sub->from('place_tag')
            ->join('tags', 'tags.id', '=', 'place_tag.tag_id')
            ->whereColumn('place_tag.place_id', 'places.id')
            ->whereIn('tags.slug', $slugs));
    }

    /**
     * Places traceable to accounts the user follows (T-037): a place_source
     * whose share belongs to a followed user, or whose source post is
     * credited to a followed influencer.
     *
     * @param  Builder<Place>  $query
     */
    protected function scopeFollowedBy(Builder $query, User $user): void
    {
        $query->whereExists(fn ($sub) => $sub->from('place_sources')
            ->join('shares', 'shares.id', '=', 'place_sources.share_id')
            ->join('source_posts', 'source_posts.id', '=', 'place_sources.source_post_id')
            ->whereColumn('place_sources.place_id', 'places.id')
            // Attribution only through PUBLISHED shares — a resolved-but-
            // failed share must not whisper "someone you follow was here".
            ->where('shares.status', ShareStatus::Published->value)
            ->where(fn ($w) => $w
                ->whereIn('shares.user_id', fn ($f) => $f->select('followee_id')->from('follows')
                    ->where('follower_user_id', $user->id)->where('followee_type', 'user'))
                ->orWhereIn('source_posts.influencer_id', fn ($f) => $f->select('followee_id')->from('follows')
                    ->where('follower_user_id', $user->id)->where('followee_type', 'influencer'))));
    }

    /**
     * Public routes bind by slug (canonical, T-030) but numeric ids keep
     * working — map pins and existing clients address places by id.
     */
    public function resolveRouteBinding($value, $field = null): ?Place
    {
        $field ??= ctype_digit((string) $value) ? 'id' : 'slug';

        return $this->where($field, $value)->first();
    }

    /** @return HasMany<PlaceSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(PlaceSource::class);
    }

    /**
     * Native user reviews (1–5 stars) — distinct from the cached Google snippets.
     *
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Discovery tags materialized from extraction snapshots on publish (T-031).
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot(['source', 'confidence']);
    }

    /**
     * Private per-user annotations (T-064) — owner-only, distinct from the
     * public discovery `tags()` above. Always constrain by the caller's
     * user_id when loading; a place detail only ever exposes the viewer's own.
     *
     * @return HasMany<UserPlaceTag, $this>
     */
    public function userPlaceTags(): HasMany
    {
        return $this->hasMany(UserPlaceTag::class);
    }

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
            'tags' => $this->tags->pluck('slug')->all(),
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

    /**
     * Union + dedupe the discovery tags across every place_source's frozen
     * extraction snapshot. `cuisines`/`vibe_tags`/`dietary_tags` are string lists;
     * `dishes` are `{name, shown_in_video}` objects deduped by name (first wins).
     * Pure: it reads the already-loaded `sources` relation, issuing no queries.
     *
     * @return array{cuisines: list<string>, vibe_tags: list<string>, dietary_tags: list<string>, dishes: list<array{name: string, shown_in_video: bool, price: string|null}>}
     */
    public function aggregatedTags(): array
    {
        $cuisines = [];
        $vibeTags = [];
        $dietaryTags = [];
        /** @var array<string, array{name: string, shown_in_video: bool, price: string|null}> $dishes */
        $dishes = [];

        foreach ($this->sources as $source) {
            $snapshot = $source->extraction_snapshot_json;

            foreach ($this->stringList($snapshot['cuisines'] ?? null) as $value) {
                $cuisines[$value] = $value;
            }
            foreach ($this->stringList($snapshot['vibe_tags'] ?? null) as $value) {
                $vibeTags[$value] = $value;
            }
            foreach ($this->stringList($snapshot['dietary_tags'] ?? null) as $value) {
                $dietaryTags[$value] = $value;
            }

            if (is_array($snapshot['dishes'] ?? null)) {
                foreach ($snapshot['dishes'] as $dish) {
                    if (! is_array($dish)) {
                        continue;
                    }
                    $name = trim((string) ($dish['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $priceRaw = $dish['price'] ?? null;
                    $price = is_string($priceRaw) && trim($priceRaw) !== '' ? trim($priceRaw) : null;
                    if (isset($dishes[$name])) {
                        // First occurrence wins for the dish, but a later source
                        // can fill in a price the first one lacked (menu update).
                        if ($dishes[$name]['price'] === null && $price !== null) {
                            $dishes[$name]['price'] = $price;
                        }

                        continue;
                    }
                    $dishes[$name] = [
                        'name' => $name,
                        'shown_in_video' => (bool) ($dish['shown_in_video'] ?? false),
                        'price' => $price,
                    ];
                }
            }
        }

        return [
            'cuisines' => array_values($cuisines),
            'vibe_tags' => array_values($vibeTags),
            'dietary_tags' => array_values($dietaryTags),
            'dishes' => array_values($dishes),
        ];
    }

    /**
     * When the dish/menu list was last refreshed — the most recent source that
     * contributed any dishes (its snapshot is frozen at publish, so its
     * `created_at` is when those dishes landed on the place). Null if no source
     * carries dishes. Reads the already-loaded `sources` relation (no queries).
     */
    public function dishesUpdatedAt(): ?string
    {
        $latest = null;
        foreach ($this->sources as $source) {
            $snapshot = $source->extraction_snapshot_json;
            $dishes = $snapshot['dishes'] ?? null;
            if (! is_array($dishes) || $dishes === []) {
                continue;
            }
            if ($source->created_at !== null && ($latest === null || $source->created_at->gt($latest))) {
                $latest = $source->created_at;
            }
        }

        return $latest?->toIso8601ZuluString();
    }

    /**
     * Coerce a snapshot value to a deduped list of non-empty trimmed strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    /**
     * The first/credited source — carries the headline influencer.
     *
     * @return HasOne<PlaceSource, $this>
     */
    public function primarySource(): HasOne
    {
        return $this->hasOne(PlaceSource::class)->where('is_primary', true);
    }

    /** @return BelongsTo<Place, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'merged_into_place_id');
    }

    /**
     * Stage the geography point for the next insert/update. `ST_MakePoint` takes
     * (lng, lat) — reversing them puts the pin in the ocean (the classic PostGIS
     * bug). Coordinates are floats from the geocoder, so inlining is injection-safe.
     */
    public function setPoint(float $lat, float $lng): void
    {
        if (! is_finite($lat) || ! is_finite($lng)) {
            throw new \InvalidArgumentException('Place coordinates must be finite.');
        }

        // number_format is locale-independent (unlike %f, which honors LC_NUMERIC
        // and would emit a comma decimal → a broken multi-arg ST_MakePoint call).
        $this->attributes['location'] = new Expression(sprintf(
            'ST_MakePoint(%s, %s)::geography',
            number_format($lng, 8, '.', ''),
            number_format($lat, 8, '.', ''),
        ));
    }

    /**
     * Read the stored point back as decimal degrees.
     *
     * @return array{lat: float, lng: float}
     */
    public function coordinates(): array
    {
        $row = DB::selectOne(
            'SELECT ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng FROM places WHERE id = ?',
            [$this->id]
        );

        return ['lat' => (float) $row->lat, 'lng' => (float) $row->lng];
    }

    /** Lowercase, accent-fold, drop punctuation and trailing legal suffixes. */
    public static function normalizeName(string $name): string
    {
        $value = Str::of($name)->ascii()->lower()->toString();
        $value = (string) preg_replace('/[^a-z0-9\s]/', ' ', $value);
        // Strip common company/legal suffixes so "Joe's Ltd" ≈ "Joe's".
        $value = (string) preg_replace('/\b(ltd|limited|inc|incorporated|llc|llp|co|corp|gmbh|sa|srl|bv|plc|pty)\b/', ' ', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /** Globally-unique slug: name stem + short random suffix. */
    public static function makeSlug(string $name): string
    {
        $stem = Str::slug($name) ?: 'place';

        return Str::limit($stem, 260, '').'-'.Str::lower(Str::random(6));
    }
}
