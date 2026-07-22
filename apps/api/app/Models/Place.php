<?php

namespace App\Models;

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Services\Reviews\ReviewSourceRegistry;
use App\Services\Reviews\ReviewSourceSummary;
use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
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
 * @property string|null $image_url
 * @property string|null $thumbnail_url
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
    /** @use HasFactory<PlaceFactory> */
    use HasFactory;

    use Searchable;

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
        'opening_hours_json', 'locked_fields', 'enriched_at', 'status',
        'merged_into_place_id', 'shares_count', 'avg_extraction_confidence',
        'normalized_name', 'google_rating', 'google_rating_count', 'google_reviews_json',
        'google_reviews_synced_at',
    ];

    /**
     * Curated business fields a human may hand-set (T-084) and thereby lock. The
     * enricher and re-share resolve backfills only ever touch a field in this set
     * when it is NOT in {@see lockedFields()} — a manual override always wins.
     *
     * @var list<string>
     */
    public const CURATED_FIELDS = [
        'name', 'address_line1', 'address_line2', 'city', 'region', 'postal_code',
        'country_code', 'cuisine_primary', 'price_range', 'phone', 'website',
        'image_url', 'thumbnail_url', 'opening_hours_json',
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
     * Places carrying ALL of the given tag slugs (AND) — each selected tag
     * narrows the results, the way a multi-select filter is expected to (picking
     * more tags returns fewer, not more, places). One EXISTS per distinct slug,
     * every one required. The place_tag pivot lands in T-031 — until it exists
     * this is a validated no-op (schema-guarded), so both the map and the browse
     * index accept tags[] today.
     *
     * @param  Builder<Place>  $query
     * @param  list<string>  $slugs
     */
    protected function scopeAllTagSlugs(Builder $query, array $slugs): void
    {
        if ($slugs === [] || ! Schema::hasTable('place_tag')) {
            return;
        }

        foreach (array_unique($slugs) as $slug) {
            $query->whereExists(fn ($sub) => $sub->from('place_tag')
                ->join('tags', 'tags.id', '=', 'place_tag.tag_id')
                ->whereColumn('place_tag.place_id', 'places.id')
                ->where('tags.slug', $slug));
        }
    }

    /**
     * Places offering a payment discount for the given card/bank/wallet (T-079).
     * Matches a place_source snapshot whose `discounts[]` carries the token as its
     * resolved issuer, scheme, or `@handle` — the SAME label `PlaceAggregations::discountCard()`
     * computes for display, so the map/index filter and the shown chips agree.
     * Case-insensitive; a blank token is a no-op.
     *
     * @param  Builder<Place>  $query
     */
    protected function scopeWithPaymentCard(Builder $query, string $card): void
    {
        $card = mb_strtolower(trim($card));
        if ($card === '') {
            return;
        }

        $query->whereExists(fn ($sub) => $sub->from('place_sources')
            ->whereColumn('place_sources.place_id', 'places.id')
            ->whereRaw(
                'EXISTS (SELECT 1 FROM jsonb_array_elements('.self::DISCOUNTS_JSONB.
                ') AS d WHERE lower('.self::DISCOUNT_CARD_SQL.') = ? AND '.self::DISCOUNT_HAS_TERMS.')',
                [$card],
            ));
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
     * Places evidenced by a user's PUBLISHED shares (T-036/T-071) — the public
     * subset behind their profile map and places list. Sibling to
     * {@see scopeMine()}; shared by ProfileController::map() and places() so the
     * two views can never disagree on what "their published places" means.
     *
     * @param  Builder<Place>  $query
     */
    protected function scopePublishedBy(Builder $query, User $user): void
    {
        $query->whereHas(
            'sources.share',
            fn ($s) => $s->where('user_id', $user->id)->where('status', ShareStatus::Published),
        );
    }

    /**
     * The caller's personal collection (T-071, ADR-071): a place is "mine" when
     * I published a share resolving to it, OR I saved it to one of my lists —
     * AND I have not soft-hidden that specific pin. A query scope over the
     * canonical (global, deduped) places; saving another user's place makes it
     * mine. The hide is per-PLACE ({@see HiddenPlace}), so removing one pin of a
     * multi-place post leaves its siblings — the earlier per-share dismissal hid
     * every place the share resolved to.
     *
     * @param  Builder<Place>  $query
     */
    protected function scopeMine(Builder $query, User $user): void
    {
        $query
            // Not a pin I've removed from my map (per-place soft-hide).
            ->whereNotExists(fn ($h) => $h->from('hidden_places')
                ->whereColumn('hidden_places.place_id', 'places.id')
                ->where('hidden_places.user_id', $user->id))
            ->where(fn (Builder $w) => $w
                // Shared by me through a published share.
                ->whereExists(fn ($sub) => $sub->from('place_sources')
                    ->join('shares', 'shares.id', '=', 'place_sources.share_id')
                    ->whereColumn('place_sources.place_id', 'places.id')
                    ->where('shares.user_id', $user->id)
                    ->where('shares.status', ShareStatus::Published->value))
                // OR saved to one of my lists.
                ->orWhereExists(fn ($sub) => $sub->from('place_list_items')
                    ->join('place_lists', 'place_lists.id', '=', 'place_list_items.place_list_id')
                    ->whereColumn('place_list_items.place_id', 'places.id')
                    ->where('place_lists.user_id', $user->id)));
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
     * Curated fields a human has hand-set and thereby locked (T-084). Always a
     * list of {@see CURATED_FIELDS} names; an unset/legacy row reads as empty.
     *
     * @return list<string>
     */
    public function lockedFields(): array
    {
        $locked = $this->locked_fields;

        return is_array($locked) ? array_values(array_intersect(self::CURATED_FIELDS, $locked)) : [];
    }

    /** Whether a human owns this field, so enrichment/resolve must not touch it. */
    public function isFieldLocked(string $field): bool
    {
        return in_array($field, $this->lockedFields(), true);
    }

    /**
     * Mark the given curated fields as human-owned (T-084) — merged into the
     * existing set, deduped, and confined to {@see CURATED_FIELDS}. Stages the
     * attribute only; the caller persists. Unknown field names are ignored.
     *
     * @param  iterable<string>  $fields
     */
    public function lockFields(iterable $fields): void
    {
        $merged = array_unique([...$this->lockedFields(), ...$fields]);
        $this->locked_fields = array_values(array_intersect(self::CURATED_FIELDS, $merged));
    }

    /**
     * Drop any locked (human-owned) keys from an enrichment/backfill patch so a
     * manual override survives (T-084). Non-curated keys pass through untouched.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function withoutLockedFields(array $patch): array
    {
        return array_filter(
            $patch,
            fn (string $field) => ! $this->isFieldLocked($field),
            ARRAY_FILTER_USE_KEY,
        );
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
