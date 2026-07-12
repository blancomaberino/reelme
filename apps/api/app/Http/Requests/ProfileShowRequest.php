<?php

namespace App\Http\Requests;

use App\Models\User;

/**
 * GET /users/{username} (T-036). The privacy gate lives in authorize() —
 * FormRequest authorization runs BEFORE validation, so an invalid query param
 * can never turn into a 422-vs-404 existence oracle for private accounts.
 */
class ProfileShowRequest extends FeedRequest
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
