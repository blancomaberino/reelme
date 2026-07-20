<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlatformAccountResource;
use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Link/unlink a user's platform accounts (03-api-design §2.3, T-015). Only
 * Instagram is supported today (others 422). The OAuth callback is public but
 * carries a signed, single-use state nonce that binds it to the user who
 * initiated the link — without it, an attacker could link their own Instagram
 * to a victim's Reelmap account (login CSRF).
 */
class PlatformAccountController extends Controller
{
    /** Cache-key prefix + TTL for the single-use OAuth state nonce. */
    private const STATE_PREFIX = 'platform_link:';

    private const STATE_TTL = 600; // 10 minutes

    /** Mobile deep link the in-app browser returns to after the callback. */
    private const DEEP_LINK = 'reelmap://platform-linked';

    /** GET /platform-accounts — the caller's linked accounts (never any token). */
    public function index(Request $request): JsonResponse
    {
        $accounts = PlatformAccount::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('platform')
            ->get();

        return response()->json([
            'data' => PlatformAccountResource::collection($accounts),
            'meta' => (object) [],
        ]);
    }

    /**
     * POST /platform-accounts/{platform}/link — start the OAuth flow. Returns the
     * provider authorize URL carrying a signed state nonce; the app opens it in an
     * in-app browser.
     */
    public function link(Request $request, string $platform): JsonResponse
    {
        $this->assertInstagram($platform);

        if (! $this->configured()) {
            return $this->error('link_unavailable', 'Account linking is not configured.', 503);
        }

        // Single-use nonce binds the unauthenticated callback back to this user.
        $nonce = Str::random(40);
        Cache::put(self::STATE_PREFIX.$nonce, (int) $request->user()->id, self::STATE_TTL);

        $url = $this->driver()
            ->scopes($this->scopes())
            ->redirectUrl((string) config('services.instagram.redirect'))
            ->with(['state' => $nonce])
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'data' => ['authorize_url' => $url],
            'meta' => (object) [],
        ]);
    }

    /**
     * GET /platform-accounts/{platform}/callback — public, state-signed. Verifies
     * the nonce, exchanges the code, upserts the account, and bounces the in-app
     * browser back to the app via a deep link carrying the outcome.
     */
    public function callback(Request $request, string $platform): RedirectResponse
    {
        if ($platform !== Platform::Instagram->value) {
            return $this->deepLink($platform, 'error');
        }

        // Verify + consume the single-use nonce (login-CSRF guard).
        $state = (string) $request->query('state', '');
        $userId = $state !== '' ? Cache::pull(self::STATE_PREFIX.$state) : null;
        if (! is_int($userId)) {
            return $this->deepLink($platform, 'invalid_state');
        }

        try {
            /** @var SocialiteUser $oauthUser */
            $oauthUser = $this->driver()->user();
        } catch (\Throwable) {
            // Bad/expired code, provider error — never leak details to the browser.
            return $this->deepLink($platform, 'error');
        }

        // The initiating user may have been deleted during the 10-min window;
        // upserting against a dangling id would FK-violate (500). Bail cleanly.
        if (! User::whereKey($userId)->exists()) {
            return $this->deepLink($platform, 'error');
        }

        $externalId = (string) $oauthUser->getId();

        // One Instagram identity, one Reelmap user: block linking an account
        // already owned by someone else (unique(platform, external_user_id)).
        $ownedByOther = PlatformAccount::query()
            ->where('platform', Platform::Instagram)
            ->where('external_user_id', $externalId)
            ->where('user_id', '!=', $userId)
            ->exists();
        if ($ownedByOther) {
            return $this->deepLink($platform, 'conflict');
        }

        try {
            PlatformAccount::updateOrCreate(
                ['user_id' => $userId, 'platform' => Platform::Instagram],
                [
                    'external_user_id' => $externalId,
                    'handle' => $this->handleFor($oauthUser, $externalId),
                    'access_token' => $oauthUser->token,
                    'refresh_token' => $oauthUser->refreshToken ?: null,
                    'token_expires_at' => $this->expiryFor($oauthUser),
                    'scopes' => $this->grantedScopes($oauthUser),
                    'last_synced_at' => now(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // Race: another user linked this same IG identity between the check
            // above and the insert — surface the intended conflict, not a 500.
            return $this->deepLink($platform, 'conflict');
        }

        return $this->deepLink($platform, 'ok');
    }

    /** DELETE /platform-accounts/{platformAccount} — owner-only unlink. */
    public function destroy(Request $request, PlatformAccount $platformAccount): JsonResponse
    {
        $this->authorize('delete', $platformAccount);

        // Best-effort remote revocation would go here (Instagram has no documented
        // revoke endpoint for Instagram Login tokens — deleting the row is enough
        // to stop all use); leave the seam and drop the local record.
        $platformAccount->delete();

        return response()->json(['data' => null, 'meta' => (object) []]);
    }

    /** Only Instagram is linkable today — everything else is a 422. */
    private function assertInstagram(string $platform): void
    {
        if ($platform !== Platform::Instagram->value) {
            abort($this->error('unsupported_platform', 'Only Instagram accounts can be linked right now.', 422, ['platform' => $platform]));
        }
    }

    /** The configured Instagram driver in stateless mode (we verify state ourselves). */
    private function driver(): AbstractProvider
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('instagram');

        return $driver->stateless();
    }

    private function configured(): bool
    {
        return (string) config('services.instagram.client_id') !== ''
            && (string) config('services.instagram.client_secret') !== '';
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        /** @var array<int, string> $scopes */
        $scopes = (array) config('services.instagram.scopes', ['instagram_business_basic']);

        return $scopes === [] ? ['instagram_business_basic'] : array_values($scopes);
    }

    /**
     * Scopes the user actually granted, falling back to what we requested when the
     * provider doesn't echo them back.
     *
     * @return array<int, string>
     */
    private function grantedScopes(SocialiteUser $user): array
    {
        $approved = array_values((array) $user->approvedScopes);

        return $approved !== [] ? $approved : $this->scopes();
    }

    private function handleFor(SocialiteUser $user, string $externalId): string
    {
        $nickname = $user->getNickname();

        return is_string($nickname) && trim($nickname) !== '' ? ltrim(trim($nickname), '@') : $externalId;
    }

    private function expiryFor(SocialiteUser $user): ?Carbon
    {
        // expiresIn is @var int but a provider may leave it null at runtime; the
        // > 0 guard is null-safe and skips a bogus "expires now".
        return $user->expiresIn > 0 ? now()->addSeconds($user->expiresIn) : null;
    }

    private function deepLink(string $platform, string $status): RedirectResponse
    {
        return redirect()->away(self::DEEP_LINK.'?'.http_build_query([
            'platform' => $platform,
            'status' => $status,
        ]));
    }

    /**
     * The canonical error envelope (03 §1). Reuses the request-scoped request_id
     * so it matches the rest of the request's logging (mirrors ApiExceptionRenderer).
     *
     * @param  array<string, mixed>  $details
     */
    private function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $requestId = request()->attributes->get('request_id');

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'request_id' => 'req_'.(is_string($requestId) && $requestId !== '' ? $requestId : (string) Str::ulid()),
            ],
        ], $status);
    }
}
