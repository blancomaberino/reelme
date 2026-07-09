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

    // Written by the media pipeline (T-017), not user request input.
    protected $fillable = [
        'source_post_id', 'kind', 'storage_path', 'disk', 'mime', 'bytes',
        'duration_ms', 'width', 'height', 'sha256', 'frame_at_ms',
    ];

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
