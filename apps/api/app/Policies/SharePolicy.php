<?php

namespace App\Policies;

use App\Models\Share;
use App\Models\User;

class SharePolicy
{
    public function view(User $user, Share $share): bool
    {
        // Admins may inspect any share (the T-035 debugging panel is
        // read-only); update/delete stay strictly owner-only.
        return $share->user_id === $user->id || $user->is_admin;
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
