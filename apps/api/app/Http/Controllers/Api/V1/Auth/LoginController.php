<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\EmailNotVerifiedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || $user->password === null || ! Hash::check((string) $request->string('password'), $user->password)) {
            // Uniform failure — never reveal whether the email exists.
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // Hard email-verification gate at login (T-066): after the first logout
        // an unverified account cannot sign in until it confirms. Thrown only
        // after a valid password, so it reveals nothing to a non-owner.
        if (! $user->hasVerifiedEmail()) {
            throw new EmailNotVerifiedException($user->email);
        }

        $deviceName = (string) $request->string('device_name');

        // One token per device: drop any existing token with the same name first.
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
            'meta' => (object) [],
        ]);
    }
}
