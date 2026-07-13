<?php

namespace App\Models;

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
 * @property bool $is_public
 */
class PlaceList extends Model
{
    /** @use HasFactory<\Database\Factories\PlaceListFactory> */
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
        });
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
