<?php

namespace App\Http\Requests;

use App\Models\User;

/**
 * GET /users/{username}/places (T-071). Same pre-validation privacy gate as
 * {@see ProfileMapRequest}/{@see ProfileShowRequest}: a private profile must
 * 404 *before* query-param validation, so an invalid facet (bad country, sort,
 * cursor, …) can never turn into a 422-vs-404 existence oracle for private
 * usernames. Inherits the faceted-listing rules from {@see PlaceListingRequest}.
 */
class ProfilePlacesRequest extends PlaceListingRequest
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
