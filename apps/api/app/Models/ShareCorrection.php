<?php

namespace App\Models;

use Database\Factories\ShareCorrectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single corrected leaf field captured when a user amends an extraction in
 * review (T-024, 04 §7). `model_value`/`user_value` are jsonb so any leaf shape
 * (scalar, list, object) round-trips; together they form the model-eval corpus.
 *
 * @property int $id
 * @property int $share_id
 * @property string $field_path
 * @property mixed $model_value
 * @property mixed $user_value
 */
class ShareCorrection extends Model
{
    /** @use HasFactory<ShareCorrectionFactory> */
    use HasFactory;

    protected $fillable = ['share_id', 'field_path', 'model_value', 'user_value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'model_value' => 'array',
            'user_value' => 'array',
        ];
    }

    /** @return BelongsTo<Share, $this> */
    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class);
    }
}
