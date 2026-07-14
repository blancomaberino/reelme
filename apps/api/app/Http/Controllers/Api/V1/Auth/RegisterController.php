<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, EmailVerificationService $verification): JsonResponse
    {
        $user = User::create($request->safe()->except('device_name'));

        // Reload so DB-side defaults (role flags, is_public) are reflected in the
        // response rather than appearing as null on the freshly built model.
        $user->refresh();

        // Email a confirmation code. The account is usable this first session
        // (email_verified_at is null → the app shows a "verify" banner), but the
        // user must confirm before they can log in again after logging out (T-066).
        $verification->issue($user);

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
