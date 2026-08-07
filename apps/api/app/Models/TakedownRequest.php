<?php

namespace App\Models;

use App\Enums\TakedownRequesterRole;
use App\Enums\TakedownStatus;
use Database\Factories\TakedownRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rightsholder or influencer asking us to stop using their material
 * (T-049, IR-2 / R-07).
 *
 * Ops-entered from the `dmca@` inbox; no public API by design — see the
 * migration for why a self-service takedown endpoint would be a weapon.
 *
 * @property int $id
 * @property string $requester_name
 * @property string $requester_email
 * @property TakedownRequesterRole $requester_role
 * @property int|null $source_post_id
 * @property string|null $target_url
 * @property string|null $notes
 * @property TakedownStatus $status
 * @property array<string, mixed>|null $outcome_json
 */
class TakedownRequest extends Model
{
    /** @use HasFactory<TakedownRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'requester_name', 'requester_email', 'requester_role',
        'source_post_id', 'target_url', 'notes', 'status',
    ];

    protected $attributes = ['status' => TakedownStatus::Received->value];

    /** @return BelongsTo<SourcePost, $this> */
    public function sourcePost(): BelongsTo
    {
        return $this->belongsTo(SourcePost::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requester_role' => TakedownRequesterRole::class,
            'status' => TakedownStatus::class,
            'outcome_json' => 'array',
            'actioned_at' => 'datetime',
        ];
    }
}
