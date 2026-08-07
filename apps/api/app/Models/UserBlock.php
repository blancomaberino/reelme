<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user blocking another (T-054, IR-6 / Apple 1.2).
 *
 * The row is directional — A blocking B is A's decision and only A can undo it
 * — but the EFFECT is mutual: neither sees the other's content. That asymmetry
 * is deliberate. A symmetric row would let B lift A's block by removing their
 * own, and a one-directional effect would leave the blocker still visible to
 * the person they blocked, which is exactly the situation blocking exists to
 * end.
 *
 * @property int $id
 * @property int $blocker_id
 * @property int $blocked_id
 */
class UserBlock extends Model
{
    protected $fillable = ['blocker_id', 'blocked_id'];

    /** @return BelongsTo<User, $this> */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    /** @return BelongsTo<User, $this> */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}
