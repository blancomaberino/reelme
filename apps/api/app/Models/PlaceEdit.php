<?php

namespace App\Models;

use App\Services\Places\PlaceEditor;
use Database\Factories\PlaceEditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a place's curated-field audit trail (T-084): who changed what,
 * and how it was applied. `changes` is a per-field diff, {field: {from, to}}.
 * Written on a Filament manual edit, an "enrich as business" run, or a system
 * write — one code path via {@see PlaceEditor}.
 *
 * @property int $id
 * @property int $place_id
 * @property int|null $user_id
 * @property string $origin
 * @property array<string, array{from: mixed, to: mixed}> $changes
 * @property string|null $note
 * @property Carbon $created_at
 */
class PlaceEdit extends Model
{
    /** @use HasFactory<PlaceEditFactory> */
    use HasFactory;

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_ENRICHMENT = 'enrichment';

    public const ORIGIN_SYSTEM = 'system';

    protected $fillable = [
        'place_id', 'user_id', 'origin', 'changes', 'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /** @return BelongsTo<Place, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
