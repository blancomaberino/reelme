<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Pure bearer API: the current token is always a PersonalAccessToken.
        $user->currentAccessToken()->delete();

        return response()->json([
            'data' => ['ok' => true],
            'meta' => (object) [],
        ]);
    }
}
