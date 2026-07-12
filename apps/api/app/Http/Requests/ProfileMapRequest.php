<?php

namespace App\Http\Requests;

use App\Models\User;

/**
 * GET /users/{username}/map (T-036). Same pre-validation privacy gate as
 * {@see ProfileShowRequest} — bbox validation errors must not reveal whether
 * a private username exists.
 */
class ProfileMapRequest extends MapPlacesRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');
        if ($user instanceof User
            && ! $user->is_public
            && $this->user('sanctum')?->id !== $user->id) {
            abort(404);
        }

        return true;
    }
}
