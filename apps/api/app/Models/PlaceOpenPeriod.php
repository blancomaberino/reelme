<?php

namespace App\Models;

use App\Support\OpeningSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opening span of a place's week, projected from
 * `places.opening_hours_periods_json` so that "open now" can be a WHERE clause
 * (T-158).
 *
 * Derived state with a rebuild path (`reelmap:open-periods:backfill`), written
 * only by {@see App\Services\Places\OpenPeriodMaterializer}. Nothing should
 * create one by hand: the interval arithmetic that produces `close_minute` —
 * which may exceed a week — belongs to {@see OpeningSchedule::intervals()}.
 *
 * No timestamps. A projection row has no history worth keeping: it is deleted
 * and rewritten wholesale whenever its source changes, so `updated_at` would
 * only ever record when the REPLACE ran.
 *
 * @property int $id
 * @property int $place_id
 * @property int $open_minute Minutes from Sunday 00:00 local time.
 * @property int $close_minute Exclusive end, in the same units; MAY EXCEED a week (10080) when the span crosses midnight or the week boundary.
 * @property string $timezone IANA zone id, proven acceptable to both PHP and Postgres at write time.
 */
class PlaceOpenPeriod extends Model
{
    public $timestamps = false;

    protected $fillable = ['place_id', 'open_minute', 'close_minute', 'timezone'];

    protected function casts(): array
    {
        return [
            'open_minute' => 'integer',
            'close_minute' => 'integer',
        ];
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
