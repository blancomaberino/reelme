<?php

namespace App\Policies;

use App\Models\PlatformAccount;
use App\Models\User;

class PlatformAccountPolicy
{
    /** A linked account is strictly owner-scoped — only its owner may unlink it. */
    public function delete(User $user, PlatformAccount $account): bool
    {
        return $account->user_id === $user->id;
    }
}
