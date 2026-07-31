<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefreshController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = $user->currentAccessToken();

        // Rotate: issue a new token for the same device, then revoke the old one.
        $token = $user->createToken($current->name)->plainTextToken;
        $current->delete();

        return ApiResponse::item([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }
}
