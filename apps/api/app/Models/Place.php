<?php

namespace App\Models;

use App\Enums\PlaceStatus;
use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 */
class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use HasFactory;

    // Written by the resolver/merger only; `location` is set via setPoint(), not
    // mass-assignment (it carries a raw SQL expression, not a scalar).
    protected $fillable = [
        'name', 'slug', 'address_line1', 'address_line2', 'city', 'region',
        'postal_code', 'country_code', 'google_place_id', 'cuisine_primary',
        'price_range', 'phone', 'website', 'opening_hours_json', 'status',
        'merged_into_place_id', 'shares_count', 'avg_extraction_confidence',
        'normalized_name',
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

    /** @return HasMany<PlaceSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(PlaceSource::class);
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
