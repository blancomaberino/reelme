<?php

namespace App\Models;

use App\Enums\Platform;
use Database\Factories\InfluencerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property Platform $platform
 * @property string $handle
 * @property string|null $display_name
 * @property string|null $avatar_url
 */
class Influencer extends Model
{
    /** @use HasFactory<InfluencerFactory> */
    use HasFactory;

    // Identity/profile fields set by the ingest pipeline. `claimed_by_user_id`
    // and `claimed_at` are granted only by the verified claim flow (T-038),
    // never mass-assigned.
    protected $fillable = [
        'platform', 'handle', 'display_name', 'avatar_url',
        'follower_count_cached', 'follower_count_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'claimed_at' => 'datetime',
            'follower_count_synced_at' => 'datetime',
            'follower_count_cached' => 'integer',
        ];
    }

    /** @return HasMany<SourcePost, $this> */
    public function sourcePosts(): HasMany
    {
        return $this->hasMany(SourcePost::class);
    }

    /** @return BelongsTo<User, $this> */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }
}
