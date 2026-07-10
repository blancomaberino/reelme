<?php

namespace App\Adapters\Data;

use App\Enums\Platform;

/**
 * Lightweight token carrier passed to adapters that can fetch private/authed
 * content. Deliberately NOT the PlatformAccount model (that table arrives in
 * T-015, which maps model → this DTO) so this interface never depends on it.
 */
final readonly class LinkedAccount
{
    public function __construct(
        public Platform $platform,
        public string $externalUserId,
        public string $handle,
        public string $accessToken,
    ) {}
}
