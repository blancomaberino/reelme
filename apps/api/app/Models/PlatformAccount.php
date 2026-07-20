<?php

namespace App\Models;

use App\Adapters\Data\LinkedAccount;
use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Database\Factories\PlatformAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's linked platform account (02-data-model §3.2, T-015). Holds the OAuth
 * tokens the ingestion pipeline uses to fetch private posts the sharer is
 * authorized to see (mapped to a {@see LinkedAccount} DTO for the adapters).
 *
 * Tokens are encrypted at rest (`encrypted` casts) and hidden from every
 * serialization ($hidden) — they must never reach an API response or a log.
 *
 * @property int $id
 * @property int $user_id
 * @property Platform $platform
 * @property string $external_user_id
 * @property string $handle
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $token_expires_at
 * @property array<int, string> $scopes
 * @property CarbonImmutable|null $last_synced_at
 */
class PlatformAccount extends Model
{
    /** @use HasFactory<PlatformAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
        'external_user_id',
        'handle',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'last_synced_at',
    ];

    /**
     * Tokens must never serialize into an API response (defence in depth on top
     * of the resource never emitting them).
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'access_token' => 'encrypted',    // Laravel encrypted cast — never plaintext at rest
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'token_expires_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when the token has a known expiry that is in the past. A never-expiring
     * token (null expiry) is treated as active; the adapter re-verifies on use.
     */
    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /** Coarse link status surfaced to the API: `active` or `expired`. */
    public function status(): string
    {
        return $this->isExpired() ? 'expired' : 'active';
    }

    /**
     * Map to the adapter-facing token carrier (never the model — the adapter
     * contract must not depend on Eloquent). Returns null when there is no usable
     * token (unlinked-but-present row, or expired), so the caller treats an
     * expired account as "no token" rather than failing the share.
     */
    public function toLinkedAccount(): ?LinkedAccount
    {
        if ($this->access_token === null || $this->access_token === '' || $this->isExpired()) {
            return null;
        }

        return new LinkedAccount(
            platform: $this->platform,
            externalUserId: $this->external_user_id,
            handle: $this->handle,
            accessToken: $this->access_token,
        );
    }
}
