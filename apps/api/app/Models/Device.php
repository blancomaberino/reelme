<?php

namespace App\Models;

use App\Http\Controllers\Api\V1\DeviceController;
use App\Notifications\Channels\ExpoChannel;
use Carbon\CarbonImmutable;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registered Expo push target (02-data-model §3.19, T-027) — one row per
 * install. The token is unique across the table; re-registration reassigns it
 * to the current user (see {@see DeviceController}).
 * Dead tokens are pruned when Expo returns `DeviceNotRegistered` on a push
 * receipt ({@see ExpoChannel}).
 *
 * @property int $id
 * @property int $user_id
 * @property string $expo_push_token
 * @property string $platform
 * @property string|null $device_name
 * @property string|null $app_version
 * @property CarbonImmutable $last_seen_at
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'expo_push_token',
        'platform',
        'device_name',
        'app_version',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
