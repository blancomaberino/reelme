<?php

namespace App\Models;

use App\Enums\TagKind;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

/**
 * A discovery tag (02 §3.10) — cuisine/vibe/dish/diet label attached to places
 * with provenance + confidence on the pivot. `slug` is derived from `name` on
 * save; uniqueness is per (kind, slug).
 *
 * @property int $id
 * @property TagKind $kind
 * @property string $name
 * @property array<string, string>|null $name_i18n
 * @property string $slug
 * @property int|null $places_count
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use Searchable;

    protected $fillable = ['kind', 'name', 'name_i18n', 'slug'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TagKind::class,
            'name_i18n' => 'array',
        ];
    }

    /**
     * The display label for a locale (ADR-084 #2): the localized name when one
     * exists, else the canonical English `name`. `en` (and any untranslated
     * locale) always yields `name`, which IS the English label.
     */
    public function localizedName(?string $locale): string
    {
        $label = $locale !== null ? ($this->name_i18n[$locale] ?? null) : null;

        // Treat a blank override as "no translation" so it falls back, never
        // rendering an empty label.
        return $label !== null && trim($label) !== '' ? $label : $this->name;
    }

    protected static function booted(): void
    {
        static::saving(function (Tag $tag): void {
            if (($tag->getAttributes()['slug'] ?? '') === '') {
                $tag->slug = self::makeSlug((string) $tag->name);
            }
        });
    }

    /** @return BelongsToMany<Place, $this> */
    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class)->withPivot(['source', 'confidence']);
    }

    /**
     * Normalize a free-text label to a slug, or '' when it is junk (empty /
     * single character after slugging) — callers must skip those.
     */
    public static function makeSlug(string $name): string
    {
        $slug = Str::slug(Str::limit(trim($name), 80, ''));

        return mb_strlen($slug) >= 2 ? Str::limit($slug, 96, '') : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'name' => $this->name,
            // Every name the tag is known by — canonical English + each localized
            // label — so a Spanish query ("parrilla", "informal") matches a tag
            // stored in English (ADR-084 #3). Adding a locale later is a reindex,
            // not a schema/client change.
            'names' => $this->searchableNames(),
            'slug' => $this->slug,
        ];
    }

    /**
     * Distinct, non-empty names across every locale (English `name` + all
     * `name_i18n` values). Also used to index tag names on Place documents.
     *
     * @return list<string>
     */
    public function searchableNames(): array
    {
        return array_values(array_unique(array_filter(
            [$this->name, ...array_values($this->name_i18n ?? [])],
            static fn (string $n) => trim($n) !== '',
        )));
    }
}
