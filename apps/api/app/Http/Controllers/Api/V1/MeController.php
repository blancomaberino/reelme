<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['user' => new UserResource($request->user())],
            'meta' => (object) [],
        ]);
    }

    /**
     * PATCH /me — the user edits their own profile. Only validated, present keys
     * are applied (partial update); empty topic/food entries are dropped.
     */
    public function update(UpdateMeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        foreach (['favorite_topics', 'favorite_foods'] as $listKey) {
            if (array_key_exists($listKey, $data) && is_array($data[$listKey])) {
                $data[$listKey] = array_values(array_filter(
                    array_map(fn ($v) => trim((string) $v), $data[$listKey]),
                    fn ($v) => $v !== '',
                ));
            }
        }

        $user->fill($data)->save();

        return response()->json([
            'data' => ['user' => new UserResource($user->fresh())],
            'meta' => (object) [],
        ]);
    }
}
