<?php

namespace App\Http\Resources;

use App\Models\PlatformAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A linked platform account for the API (T-015). Deliberately never emits the
 * access/refresh tokens — only the safe metadata the app needs to render the
 * "linked accounts" screen.
 *
 * @mixin PlatformAccount
 */
class PlatformAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'handle' => $this->handle,
            'scopes' => $this->scopes,
            'status' => $this->status(),
            'token_expires_at' => optional($this->token_expires_at)->toIso8601String(),
            'last_synced_at' => optional($this->last_synced_at)->toIso8601String(),
        ];
    }
}
