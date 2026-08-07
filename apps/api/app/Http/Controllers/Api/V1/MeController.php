<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Quotas\QuotaSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request, QuotaSnapshot $quotas): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Quota state travels in `meta`, not on the user resource: it is a fact
        // about right now rather than about the account, and it changes on a
        // clock nothing else here does. The app reads it to say "daily limit
        // reached — resets at X" BEFORE the tap, instead of turning a 429 into
        // an apology afterwards.
        return ApiResponse::item(
            ['user' => new UserResource($user)],
            ['quotas' => $quotas->for($user)],
        );
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

        return ApiResponse::item(['user' => new UserResource($user->fresh())]);
    }
}
