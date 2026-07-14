<?php

namespace App\Models;

use Database\Factories\PlaceListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A user's named collection of places (T-062). Owner-scoped curation; when
 * `is_public` it is readable by slug for sharing (T-063). The slug is derived
 * from the name and made unique within the owner on save.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $slug
 * @property string|null $public_slug globally-unique share token, minted on first publish (T-063)
 * @property bool $is_public
 * @property-read int|null $items_count withCount('items')
 * @property-read bool|null $contains withExists, only on the ?contains index query
 */
class PlaceList extends Model
{
    /** @use HasFactory<PlaceListFactory> */
    use HasFactory;

    protected $fillable = ['name', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (PlaceList $list): void {
            // (Re)derive the slug when the name changes or none is set yet,
            // keeping it unique within the owner (never across users).
            if ($list->isDirty('name') || blank($list->slug)) {
                $list->slug = $list->uniqueSlug((string) $list->name);
            }
            // Mint a stable, globally-unique public slug the first time a list
            // goes public; never regenerate it (a rename or re-share must not
            // change an already-shared link) (T-063).
            if ($list->is_public && blank($list->public_slug)) {
                $list->public_slug = $list->uniquePublicSlug((string) $list->name);
            }
        });
    }

    /** A globally-unique, readable-but-unguessable share token: name-slug + a
     *  6-char random suffix (retried on the astronomically-rare collision). */
    private function uniquePublicSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'list';
        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (static::query()->where('public_slug', $slug)->exists());

        return $slug;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'list';
        $slug = $base;
        $n = 1;
        while (
            static::query()
                ->where('user_id', $this->user_id)
                ->where('slug', $slug)
                ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
                ->exists()
        ) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PlaceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PlaceListItem::class);
    }
}
