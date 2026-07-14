<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Email confirmation endpoints (T-066). Both are public (an unverified user is
 * logged out and can't authenticate) and rate-limited by the `throttle:auth`
 * group. Neither reveals whether an account exists.
 */
class EmailVerificationController extends Controller
{
    /** Confirm the account with the emailed code and issue a session token. */
    public function verify(VerifyEmailRequest $request, EmailVerificationService $verification): JsonResponse
    {
        $email = (string) $request->string('email');
        $user = User::where('email', $email)->first();

        // A missing user and a bad code are indistinguishable to the caller.
        if ($user === null || ! $verification->check($email, (string) $request->string('code'))) {
            throw ValidationException::withMessages([
                'code' => ['El código es inválido o venció.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        // One token per device, like login.
        $deviceName = (string) $request->string('device_name');
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

    /** Re-send the confirmation code. Always 200 (no account enumeration). */
    public function resend(ResendVerificationRequest $request, EmailVerificationService $verification): JsonResponse
    {
        $user = User::where('email', (string) $request->string('email'))->first();

        if ($user !== null && ! $user->hasVerifiedEmail()) {
            $verification->issue($user);
        }

        return response()->json([
            'data' => ['status' => 'sent'],
            'meta' => (object) [],
        ]);
    }
}
