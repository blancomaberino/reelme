<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A follow edge (02 §3.11): follower (always a user) → followee
 * (user | influencer, morph-mapped aliases). Uniqueness is DB-enforced on
 * the (follower, followee) triple.
 *
 * @property int $id
 * @property int $follower_user_id
 * @property string $followee_type
 * @property int $followee_id
 */
class Follow extends Model
{
    protected $fillable = ['follower_user_id', 'followee_type', 'followee_id'];

    /** @return BelongsTo<User, $this> */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function followee(): MorphTo
    {
        return $this->morphTo();
    }
}
