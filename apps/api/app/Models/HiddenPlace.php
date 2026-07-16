<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-user, per-place soft-hide (T-071): "remove from my map" for one pin,
 * independent of which share(s) resolved to it. Reversible — re-sharing or
 * re-saving the place deletes the row. Distinct from {@see FeedDismissal}, which
 * hides a whole share from the (deprecated) feed.
 */
class HiddenPlace extends Model
{
    protected $fillable = ['user_id', 'place_id'];
}
