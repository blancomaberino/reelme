<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserPlaceTagRequest;
use App\Http\Resources\UserPlaceTagResource;
use App\Models\Place;
use App\Models\UserPlaceTag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Private per-user place tags (T-064). Strictly personal annotations pinned to
 * a place (e.g. "visitar a las 5"). Every route is owner-scoped: a tag that
 * isn't the caller's 404s (never 403 — no existence oracle), and the listing
 * only ever contains the caller's own labels. These are NEVER aggregated into
 * the public discovery tags (TagController) or exposed to other users.
 */
class UserPlaceTagController extends Controller
{
    /** List the caller's private tags for a place (most recent first). */
    public function index(Request $request, Place $place): JsonResponse
    {
        return $this->respond($request, $place);
    }

    /** Add a private tag to a place (idempotent per (user, place, label)). */
    public function store(UserPlaceTagRequest $request, Place $place): JsonResponse
    {
        $created = $this->firstOrCreateTag(
            (int) $request->user()->id,
            (int) $place->id,
            (string) $request->validated('label'),
        );

        return $this->respond($request, $place, $created ? 201 : 200);
    }

    /**
     * Idempotently pin a private tag to a place, returning whether a row was
     * created. Matching is case-insensitive so "Visitar" and "visitar" collapse
     * to one label (first spelling wins). user_id/place_id are guarded
     * (server-derived, never mass-assigned), so they're set directly; a
     * concurrent identical insert that slips past the existence check is caught
     * on the DB's unique(user,place,label) and treated as already-present.
     */
    private function firstOrCreateTag(int $userId, int $placeId, string $label): bool
    {
        $exists = UserPlaceTag::query()
            ->where('user_id', $userId)
            ->where('place_id', $placeId)
            ->whereRaw('lower(label) = lower(?)', [$label])
            ->exists();

        if ($exists) {
            return false;
        }

        try {
            $tag = new UserPlaceTag;
            $tag->user_id = $userId;
            $tag->place_id = $placeId;
            $tag->label = $label;
            $tag->save();

            return true;
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent identical insert — already present.
            // (Safe because each statement auto-commits: do NOT wrap store() in a
            // DB transaction, or the follow-up SELECT would hit an aborted tx.)
            return false;
        }
    }

    /** Remove one of the caller's private tags from a place. */
    public function destroy(Request $request, Place $place, UserPlaceTag $tag): JsonResponse
    {
        // A tag not owned by the caller (or not on this place) is indistinguishable
        // from a missing one.
        abort_unless(
            (int) $tag->user_id === (int) $request->user()->id
            && (int) $tag->place_id === (int) $place->id,
            404,
        );

        $tag->delete();

        return $this->respond($request, $place);
    }

    /** Echo back the caller's full, current tag list for the place. */
    private function respond(Request $request, Place $place, int $status = 200): JsonResponse
    {
        $tags = UserPlaceTag::query()
            ->where('user_id', $request->user()->id)
            ->where('place_id', $place->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => UserPlaceTagResource::collection($tags),
            'meta' => (object) [],
        ], $status);
    }
}
