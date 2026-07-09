<?php

namespace App\Models;

use App\Enums\MediaKind;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'bytes' => 'integer',
            'duration_ms' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'frame_at_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<SourcePost, $this> */
    public function sourcePost(): BelongsTo
    {
        return $this->belongsTo(SourcePost::class);
    }
}
