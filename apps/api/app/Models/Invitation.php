<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A friend-invite email that was sent (T-069). `created_at` only (no updates),
 * so timestamps are disabled and set explicitly on insert.
 *
 * @property int $id
 * @property int $inviter_user_id
 * @property string $email
 */
class Invitation extends Model
{
    public $timestamps = false;

    protected $fillable = ['inviter_user_id', 'email', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }
}
