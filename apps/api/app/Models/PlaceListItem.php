<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One place in a place_list (T-062): unique per list, optional note, ordered.
 *
 * @property int $id
 * @property int $place_list_id
 * @property int $place_id
 * @property string|null $note
 * @property int $position
 */
class PlaceListItem extends Model
{
    protected $fillable = ['place_id', 'note', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<PlaceList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(PlaceList::class, 'place_list_id');
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
