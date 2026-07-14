<?php

namespace App\Models;

use Database\Factories\UserPlaceTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private, per-user label pinned to a place (T-064) — e.g. "visitar a las 5".
 * Owner-scoped personal annotation; distinct from the public discovery tags
 * (App\Models\Tag, materialized from extraction). These are NEVER surfaced to
 * other users or aggregated into the global/AI tag taxonomy. `user_id`/`place_id`
 * are set explicitly by the controller (route-scoped), not mass-assignable.
 *
 * @property int $id
 * @property int $user_id
 * @property int $place_id
 * @property string $label
 */
class UserPlaceTag extends Model
{
    /** @use HasFactory<UserPlaceTagFactory> */
    use HasFactory;

    protected $fillable = ['label'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
