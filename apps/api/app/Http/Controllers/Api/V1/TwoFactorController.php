<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Auth\PasswordConfirmationRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Managing your own second factor (T-068). Every route here is `auth:sanctum`
 * and acts on the authenticated user only — there is no user id in any
 * signature, so there is no object to fail to authorize.
 *
 * The three destructive operations (disable, regenerate codes, view codes)
 * re-ask for the password. A stolen bearer token should not be enough to strip
 * the second factor off an account, which is the whole point of having one.
 */
class TwoFactorController extends Controller
{
    /** Current state, for rendering the Security section. */
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::item([
            'enabled' => $user->hasTwoFactorEnabled(),
            // Distinguishes "never set up" from "started, never confirmed", so
            // the UI can offer Continue rather than restarting from scratch.
            'pending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_remaining' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    /**
     * Begin setup: mint a secret and hand back the otpauth URI to render as a QR.
     *
     * Nothing is enforced yet — the secret is inert until {@see confirm()}. Calling
     * this again before confirming rolls a NEW secret, which is what makes a
     * half-finished setup on a lost phone recoverable.
     */
    public function enable(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $this->user($request);

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('Two-factor authentication is already enabled.')],
            ]);
        }

        $secret = $twoFactor->generateSecret();
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = null;
        $user->save();

        return ApiResponse::item([
            // The raw secret ships too: an authenticator that cannot scan a QR
            // needs it for manual entry.
            'secret' => $secret,
            'otpauth_uri' => $twoFactor->otpauthUri($user, $secret),
            // Rendered here rather than on the device: every React Native QR
            // library draws through react-native-svg, and pulling in a native
            // module would force a full dev-client rebuild for a picture.
            'qr_png' => $twoFactor->qrCodePng($user, $secret),
        ]);
    }

    /**
     * Prove the authenticator works, then switch enforcement on.
     *
     * The recovery codes are returned HERE and only here — this is the one
     * moment the user is guaranteed to be looking at the setup screen. Seeing
     * them again costs a password.
     */
    public function confirm(ConfirmTwoFactorRequest $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $this->user($request);

        if ($user->two_factor_secret === null) {
            throw ValidationException::withMessages([
                'two_factor' => [__('Start two-factor setup before confirming it.')],
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'two_factor' => [__('Two-factor authentication is already enabled.')],
            ]);
        }

        // Burns the window like any other verification. The user is already
        // authenticated here, so nothing legitimate needs to reuse this code —
        // and leaving it live would let a code observed over someone's shoulder
        // during setup complete a login for the rest of its window.
        if (! $twoFactor->verifyAndConsume($user, (string) $request->string('code'))) {
            throw ValidationException::withMessages([
                'code' => [__('That code is not valid. Check your authenticator and try again.')],
            ]);
        }

        $codes = $twoFactor->generateRecoveryCodes();
        $user->two_factor_recovery_codes = $codes;
        $user->two_factor_confirmed_at = now();
        $user->save();

        return ApiResponse::item(['recovery_codes' => $codes]);
    }

    /** Show the remaining recovery codes again. Costs a password. */
    public function recoveryCodes(PasswordConfirmationRequest $request): JsonResponse
    {
        $user = $this->confirmPassword($request);

        return ApiResponse::item(['recovery_codes' => $user->two_factor_recovery_codes ?? []]);
    }

    /**
     * Replace the recovery codes. Costs a password.
     *
     * Regenerating invalidates every previous code, which is the correct
     * response to a list that may have been seen by someone else.
     */
    public function regenerateRecoveryCodes(PasswordConfirmationRequest $request, TwoFactorService $twoFactor): JsonResponse
    {
        $user = $this->confirmPassword($request);

        $codes = $twoFactor->generateRecoveryCodes();
        $user->two_factor_recovery_codes = $codes;
        $user->save();

        return ApiResponse::item(['recovery_codes' => $codes]);
    }

    /**
     * Turn 2FA off. Costs a password.
     *
     * Clears the replay timestamp along with the secret: leaving it behind would
     * make a later re-enable reject every code until the clock caught up to the
     * old window.
     */
    public function disable(PasswordConfirmationRequest $request): JsonResponse
    {
        $user = $this->confirmPassword($request);

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_used_ts = null;
        $user->save();

        return ApiResponse::item(['enabled' => false]);
    }

    /**
     * Re-authenticate with the password before a destructive change.
     *
     * A social-login account has no password (`password` is null); it must never
     * fall through to "no password required".
     */
    private function confirmPassword(PasswordConfirmationRequest $request): User
    {
        $user = $this->user($request);

        if ($user->password === null
            || ! Hash::check((string) $request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => [__('auth.password')],
            ]);
        }

        return $user;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
