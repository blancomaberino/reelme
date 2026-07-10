<?php

namespace App\Policies;

use App\Models\Share;
use App\Models\User;

class SharePolicy
{
    public function view(User $user, Share $share): bool
    {
        return $share->user_id === $user->id;
    }

    public function update(User $user, Share $share): bool
    {
        return $share->user_id === $user->id;
    }

    public function delete(User $user, Share $share): bool
    {
        return $share->user_id === $user->id;
    }
}
