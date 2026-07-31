<?php

namespace App\Models;

use App\Enums\PlaceStatus;
use App\Models\Builders\PlaceQueryBuilder;
use App\Services\Reviews\ReviewSourceRegistry;
use App\Services\Reviews\ReviewSourceSummary;
use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;

/**
 * A deduplicated place — one map pin (02 §3.8). `location` is PostGIS
 * `geography(Point,4326)`; it is never a plain Eloquent attribute (reads return
 * WKB), so set it via {@see setPoint()} and read coordinates via {@see coordinates()}.
 * `normalized_name` (accent-folded, suffix-stripped) and `slug` are maintained
 * on save for trigram matching and stable URLs.
 *
 * T-106 moved four concerns out into `Models\Concerns` and the six query scopes
 * into {@see PlaceQueryBuilder}: this is the highest-degree node in the
 * codebase, so every concern it carries is blast radius. What remains is
 * persistence, casts and relationships.
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
 * @property string|null $image_url
 * @property string|null $thumbnail_url
 * @property array<int, array<string, mixed>>|null $gallery_json
 * @property array<int, string>|null $locked_fields
 * @property Carbon|null $enriched_at
 * @property int $shares_count
 * @property numeric-string|null $google_rating
 * @property int|null $google_rating_count
 * @property array<int, array<string, mixed>>|null $google_reviews_json
 * @property Carbon|null $google_reviews_synced_at
 * @property int|null $reviews_count
 * @property float|numeric-string|null $reviews_avg_rating
 */
class Place extends Model
{
    use Concerns\DerivesNameColumns;
    use Concerns\HasGeoPoint;
    use Concerns\LocksFields;
    use Concerns\SearchesAsPlace;

    /** @use HasFactory<PlaceFactory> */
    use HasFactory;

    /**
     * A place_source's `discounts` snapshot as jsonb, guarded to an empty array
     * unless it is actually a JSON array — a malformed/legacy snapshot must not
     * make `jsonb_array_elements` error. Mirrors the `is_array()` guard in
     * `PlaceAggregations::discounts()`. Consumed by the T-079 card filter + facet.
     */
    public const DISCOUNTS_JSONB = "CASE WHEN jsonb_typeof(place_sources.extraction_snapshot_json->'discounts') = 'array'"
        ." THEN place_sources.extraction_snapshot_json->'discounts' ELSE '[]'::jsonb END";

    /**
     * The display card label for a `d` discount element — the SQL twin of
     * `PlaceAggregations::discountCard()` (resolved issuer → scheme → `@handle`, a leading `@`
     * on the stored handle collapsed so both sides agree). The filter and facet
     * must compute the SAME label the aggregation shows.
     */
    public const DISCOUNT_CARD_SQL = "COALESCE(NULLIF(trim(d->>'issuer'), ''), NULLIF(trim(d->>'scheme'), ''),"
        ." '@' || NULLIF(ltrim(trim(d->>'handle'), '@'), ''))";

    /**
     * A `d` discount element carries a non-empty `terms` — the SQL twin of the
     * `$terms === ''` skip in `PlaceAggregations::discounts()`. The filter + facet
     * apply it so they never match/list a card the place detail wouldn't show.
     */
    public const DISCOUNT_HAS_TERMS = "NULLIF(trim(d->>'terms'), '') IS NOT NULL";

    // Written by the resolver/merger only; `location` is set via setPoint(), not
    // mass-assignment (it carries a raw SQL expression, not a scalar).
    protected $fillable = [
        'name', 'slug', 'address_line1', 'address_line2', 'city', 'region',
        'postal_code', 'country_code', 'google_place_id', 'cuisine_primary',
        'price_range', 'phone', 'website', 'image_url', 'thumbnail_url',
        'gallery_json', 'opening_hours_json', 'locked_fields', 'enriched_at', 'status',
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
            'needs_admin_review' => 'boolean',
            'opening_hours_json' => 'array',
            'gallery_json' => 'array',
            'locked_fields' => 'array',
            'enriched_at' => 'datetime',
            'price_range' => 'integer',
            'shares_count' => 'integer',
            'avg_extraction_confidence' => 'decimal:3',
            'google_rating' => 'decimal:1',
            'google_rating_count' => 'integer',
            'google_reviews_json' => 'array',
            'google_reviews_synced_at' => 'datetime',
        ];
    }

    /**
     * Tombstone this place if a removal has left it orphaned — no published
     * source AND saved to no list (T-073). Such a place is a provenance-less
     * "ghost pin": it would otherwise linger on the public map/search with
     * `source_count` 0 after its last contributor fully removed it, or after the
     * last list holding a sourceless saved place dropped it. Marking it
     * {@see PlaceStatus::Removed} pulls it off every public/matchable surface
     * (via {@see PlaceStatus::matchable()}) while keeping the row and any
     * personal data; a later re-share revives it ({@see PlacePublisher}).
     *
     * No-op unless the place is currently matchable — never overrides a Merged
     * tombstone or an admin Hidden. A place still saved to any list is left as
     * is: a saver still wants it, and it shows only where they saved it.
     *
     * @return bool whether the place was tombstoned
     */
    public function tombstoneIfOrphaned(): bool
    {
        if (! in_array($this->status, [PlaceStatus::Pending, PlaceStatus::Active], true)) {
            return false;
        }

        $hasPublishedSource = $this->sources()->whereNotNull('published_at')->exists();
        $isSaved = PlaceListItem::query()->where('place_id', $this->id)->exists();
        if ($hasPublishedSource || $isSaved) {
            return false;
        }

        $this->status = PlaceStatus::Removed;
        $this->save();

        return true;
    }

    /**
     * Every Place query starts on {@see PlaceQueryBuilder}, which owns the query
     * vocabulary (publiclyVisible / mine / followedBy / …) that used to be six
     * `scopeX()` methods here.
     *
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): PlaceQueryBuilder
    {
        return new PlaceQueryBuilder($query);
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
     * Cached summaries from external review providers (T-082) — one row per
     * source (Trustpilot, …), refreshed out of band. Distinct from both the
     * native `reviews()` and the Google columns; read by the ReviewSource
     * drivers, never fetched inline.
     *
     * @return HasMany<ExternalPlaceReview, $this>
     */
    public function externalReviews(): HasMany
    {
        return $this->hasMany(ExternalPlaceReview::class);
    }

    /**
     * The cached external review row for one source (T-082), preferring the
     * loaded `externalReviews` relation (no N+1 on the request path) and falling
     * back to a scoped query otherwise. Shared by the ReviewSource driver and the
     * out-of-band refresher so the relation-vs-query rule lives in one place and
     * generalizes to the next external provider for free.
     */
    public function externalReview(string $source): ?ExternalPlaceReview
    {
        if ($this->relationLoaded('externalReviews')) {
            return $this->externalReviews->firstWhere('source', $source);
        }

        return $this->externalReviews()->where('source', $source)->first();
    }

    /**
     * Audit trail of curated-field changes (T-084) — manual edits, enrichment
     * runs, system writes — newest first. Powers the Filament history panel and
     * is the shared record the owner suggest-edit flow (T-083) can reuse.
     *
     * @return HasMany<PlaceEdit, $this>
     */
    public function placeEdits(): HasMany
    {
        return $this->hasMany(PlaceEdit::class)->latest();
    }

    /**
     * The place's per-source rating summaries (T-082): Google, native, Trustpilot,
     * … — whichever resolve, in registry order, each already reduced from its
     * cached signal. Powers the `review_sources[]` block on the place detail.
     * Delegates to the {@see ReviewSourceRegistry} so the model stays ignorant of
     * which providers exist or how each caches.
     *
     * @return list<ReviewSourceSummary>
     */
    public function reviewSummaries(): array
    {
        return app(ReviewSourceRegistry::class)->summarize($this);
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
     * BCP-47 language of the source that contributed the menu — dishes are kept
     * verbatim in the post's language, so the client can label the menu ("in
     * English", etc.). Prefers the primary source; null when unknown (e.g. an
     * older snapshot that predates language capture).
     */
    public function dishesLanguage(): ?string
    {
        foreach ($this->sources->sortByDesc('is_primary') as $source) {
            $snapshot = $source->extraction_snapshot_json;
            $hasDishes = is_array($snapshot['dishes'] ?? null) && $snapshot['dishes'] !== [];
            $language = $snapshot['language'] ?? null;
            if ($hasDishes && is_string($language) && $language !== '') {
                return $language;
            }
        }

        return null;
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
}
