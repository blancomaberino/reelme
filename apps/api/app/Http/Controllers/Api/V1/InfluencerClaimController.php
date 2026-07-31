<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClaimMethod;
use App\Exceptions\ClaimException;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\InfluencerClaimResource;
use App\Models\Influencer;
use App\Models\InfluencerClaim;
use App\Models\User;
use App\Services\Influencers\InfluencerClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Claim an auto-created influencer identity (T-038, 03 §2.9). Two methods:
 * `oauth` (instant handle match against a linked platform account) and
 * `bio_code` (issue a one-time code, then verify it appears in the profile bio).
 * The M3 exit criterion — "an influencer can claim their auto-created identity".
 */
class InfluencerClaimController extends Controller
{
    /** Profile-fetch cap on the bio-verify action (bounds scrape cost). */
    private const VERIFY_MAX_PER_MINUTE = 5;

    public function __construct(private readonly InfluencerClaimService $claims) {}

    /**
     * GET /influencers/{influencer}/claim — the caller's in-progress claim state
     * so the mobile flow can resume (token, status, expiry). `data: null` when
     * they have no claim on this identity.
     */
    public function show(Request $request, Influencer $influencer): JsonResponse
    {
        $claim = InfluencerClaim::query()
            ->where('influencer_id', $influencer->id)
            ->where('user_id', $this->user($request)->id)
            ->first();

        return ApiResponse::item($claim !== null ? new InfluencerClaimResource($claim) : null);
    }

    /**
     * POST /influencers/{influencer}/claim — start or advance a claim.
     * Body: `{method: "oauth"|"bio_code", action?: "verify"}`.
     */
    public function store(Request $request, Influencer $influencer): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'string', 'in:oauth,bio_code'],
            'action' => ['sometimes', 'string', 'in:verify'],
        ]);

        $user = $this->user($request);

        // Fast paths before any work: re-claiming what you own is an idempotent
        // 200; an identity owned by someone else is always a 409 (never reassigned
        // outside the Filament admin-override path).
        if ($influencer->claimed_by_user_id === $user->id) {
            return $this->respond($this->existingClaim($influencer, $user));
        }
        if ($influencer->claimed_by_user_id !== null) {
            throw ClaimException::conflict();
        }

        if ($validated['method'] === ClaimMethod::Oauth->value) {
            return $this->respond($this->claims->claimViaOAuth($influencer, $user));
        }

        // bio_code: no action → issue a token; action=verify → check the bio.
        if (($validated['action'] ?? null) === 'verify') {
            $this->throttleVerify($user);

            return $this->respond($this->claims->verifyBioCode($influencer, $user));
        }

        $claim = $this->claims->issueBioCode($influencer, $user);

        return response()->json([
            'data' => new InfluencerClaimResource($claim),
            'meta' => [
                'instructions' => "Place this code in your {$influencer->platform->label()} bio or a pinned post, then verify. It expires in 72 hours.",
            ],
        ], 200);
    }

    private function existingClaim(Influencer $influencer, User $user): InfluencerClaim
    {
        return InfluencerClaim::query()
            ->where('influencer_id', $influencer->id)
            ->where('user_id', $user->id)
            ->firstOr(fn () => $this->claims->verify($influencer, $user, ClaimMethod::Oauth));
    }

    private function respond(InfluencerClaim $claim): JsonResponse
    {
        return ApiResponse::item(new InfluencerClaimResource($claim));
    }

    /** Cap bio-verify attempts (each triggers a remote profile fetch). */
    private function throttleVerify(User $user): void
    {
        $key = 'influencer-claim-verify:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::VERIFY_MAX_PER_MINUTE)) {
            abort(429, 'Too many verification attempts. Wait a minute and try again.', [
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
        }
        RateLimiter::hit($key, 60);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
