<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One row of the notification center (T-040, 03 §2.15).
 *
 * `type` is the STABLE MACHINE STRING from `data.type` (`share.published`,
 * `social.follow`, …), never the PHP class name. The class is an implementation
 * detail that renaming or namespacing would change; the client switches on this
 * value, so it has to be part of the contract rather than a side effect of
 * where the class happens to live.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->data ?? [];

        return [
            'id' => (string) $this->id,
            // Fall back to the PHP class only if a legacy row predates the
            // `data.type` convention — better a stable-ish string than null.
            'type' => is_string($data['type'] ?? null) ? $data['type'] : $this->type,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
            'body' => is_string($data['body'] ?? null) ? $data['body'] : null,
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            'data' => (object) $data,
            'read_at' => $this->read_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
