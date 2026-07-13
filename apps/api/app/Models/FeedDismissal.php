<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's "hide from my feed" on a published share (non-destructive). The feed
 * query excludes shares the viewer has dismissed; nothing else is affected.
 *
 * @property int $id
 * @property int $user_id
 * @property int $share_id
 */
class FeedDismissal extends Model
{
    protected $fillable = ['user_id', 'share_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Share, $this> */
    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class);
    }
}
