<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->except('device_name'));

        // Reload so DB-side defaults (role flags, is_public) are reflected in the
        // response rather than appearing as null on the freshly built model.
        $user->refresh();

        $token = $user->createToken($request->string('device_name'))->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
            'meta' => (object) [],
        ], 201);
    }
}
