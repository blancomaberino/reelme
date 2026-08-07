<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\TwoFactorService;
use App\Services\Gdpr\AccountDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * The second half of a 2FA login (T-068): trade the challenge token issued by a
 * correct password for a real session token.
 *
 * Public, because the caller has no session yet — the challenge token IS the
 * proof that the password step already passed. It carries no authority of its
 * own: it is a random cache key, so it cannot be presented to any other route.
 *
 * Every rejection below is the same message. Whether the challenge expired, the
 * TOTP was wrong, or the recovery code was already spent is not information a
 * caller needs, and distinguishing them tells an attacker which half of a
 * guess landed.
 */
class TwoFactorChallengeController extends Controller
{
    public function __invoke(
        TwoFactorChallengeRequest $request,
        TwoFactorService $twoFactor,
        AccountDeletion $deletion,
    ): JsonResponse {
        $challengeToken = (string) $request->string('challenge_token');
        $user = $twoFactor->resolveChallenge($challengeToken);

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            $this->reject();
        }

        // Same gate as LoginController, on the path that mints the actual
        // token: a soft-deleted account passes only if the USER asked for the
        // deletion (never an admin ban) and the grace period is still running.
        if ($user->trashed() && ! ($deletion->isPending($user) && $deletion->isWithinGrace($user))) {
            $this->reject();
        }

        $recoveryCode = $request->string('recovery_code')->toString();

        $passed = $recoveryCode !== ''
            ? $twoFactor->consumeRecoveryCode($user, $recoveryCode)
            : $twoFactor->verifyAndConsume($user, (string) $request->string('code'));

        if (! $passed) {
            $this->reject();
        }

        // Persist whichever the verification spent — the burned TOTP window or
        // the removed recovery code. Skipping this is what would let either be
        // used a second time.
        $user->save();

        // One exchange per challenge: without this the token stays live for its
        // full TTL and a replayed request would mint a second session.
        $twoFactor->consumeChallenge($challengeToken);

        // Both factors passed — a pending deletion is now a change of mind (T-050).
        $deletion->cancel($user);

        $deviceName = (string) $request->string('device_name');
        $user->tokens()->where('name', $deviceName)->delete();

        return ApiResponse::item([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * @return never
     *
     * @throws ValidationException
     */
    private function reject(): void
    {
        throw ValidationException::withMessages([
            'code' => [__('That code is not valid or the sign-in request expired. Please sign in again.')],
        ]);
    }
}
