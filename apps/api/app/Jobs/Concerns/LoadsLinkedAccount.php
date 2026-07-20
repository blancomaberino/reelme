<?php

namespace App\Jobs\Concerns;

use App\Adapters\Data\LinkedAccount;
use App\Enums\Platform;
use App\Models\PlatformAccount;

/**
 * Loads the sharer's linked platform account (T-015) and maps it to the adapter
 * DTO. Shared by the fetch/download jobs so the authed strategy in the adapter
 * chain gets the token for a private post the sharer authorized. Returns null
 * when the user hasn't linked that platform (or the token has expired), which
 * the adapters treat as "no token" — the public/manual path still runs.
 */
trait LoadsLinkedAccount
{
    protected function linkedAccountFor(int $userId, Platform $platform): ?LinkedAccount
    {
        return PlatformAccount::query()
            ->where('user_id', $userId)
            ->where('platform', $platform)
            ->first()
            ?->toLinkedAccount();
    }
}
