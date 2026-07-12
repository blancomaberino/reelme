<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact public sharer attribution block (03 §2.6). Callers must only wrap
 * users who consented to public attribution (`is_public`) — a private sharer
 * is represented as `null`, never as an anonymized stub.
 *
 * @mixin User
 */
class UserSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'avatar_path' => $this->avatar_path,
        ];
    }
}
