<?php

namespace App\Models;

use App\Enums\FetchStatus;
use App\Enums\Platform;
use App\Enums\PostPrivacy;
use Database\Factories\SourcePostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourcePost extends Model
{
    /** @use HasFactory<SourcePostFactory> */
    use HasFactory;

    // Populated by trusted source adapters (T-012+), not user request input.
    protected $fillable = [
        'platform', 'external_id', 'url', 'influencer_id', 'caption',
        'posted_at', 'privacy', 'oembed_json', 'fetch_status', 'fetched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'privacy' => PostPrivacy::class,
            'fetch_status' => FetchStatus::class,
            'oembed_json' => 'array',
            'posted_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Influencer, $this> */
    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    /** @return HasMany<MediaAsset, $this> */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /** @return HasMany<Share, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }
}
