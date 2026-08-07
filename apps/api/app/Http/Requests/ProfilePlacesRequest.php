<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Profiles\ProfileVisibility;

/**
 * GET /users/{username}/places (T-071). Same pre-validation privacy gate as
 * {@see ProfileMapRequest}/{@see ProfileShowRequest}: a private profile must
 * 404 *before* query-param validation, so an invalid facet (bad country, sort,
 * cursor, …) can never turn into a 422-vs-404 existence oracle for private
 * usernames. Inherits the faceted-listing rules from {@see PlaceListingRequest}.
 */
class ProfilePlacesRequest extends PlaceListingRequest
{
    /**
     * Runs BEFORE param validation, so an invalid facet cannot become a
     * 404-vs-422 oracle for a profile the caller may not see.
     *
     * Delegates to {@see ProfileVisibility} rather than repeating the rule. It
     * used to hold its own copy of the private-profile check, and T-054 then
     * added blocking to the controller's copy only — leaving this one route
     * readable by an account the owner had blocked. One gate, two call sites,
     * and the second was invisible because it lived in a different file.
     */
    public function authorize(): bool
    {
        $user = $this->route('user');

        if ($user instanceof User) {
            ProfileVisibility::assert($this->user('sanctum'), $user);
        }

        return true;
    }
}
