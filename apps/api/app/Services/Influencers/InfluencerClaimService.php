<?php

namespace App\Services\Influencers;

use App\Enums\ClaimMethod;
use App\Enums\ClaimStatus;
use App\Events\InfluencerClaimed;
use App\Exceptions\ClaimException;
use App\Models\Influencer;
use App\Models\InfluencerClaim;
use App\Models\PlatformAccount;
use App\Models\User;
use App\Notifications\InfluencerClaimRejected;
use Illuminate\Support\Facades\DB;

/**
 * The influencer claiming domain (T-038, 06 §5.1). Owns the two verification
 * paths (OAuth handle match + code-in-bio), the atomic "who wins the identity"
 * transaction, and the admin dispute-resolution actions. `influencers.claimed_by_user_id`
 * is the single source of truth; the `influencer_claims` row is the audit trail.
 */
class InfluencerClaimService
{
    /** Base32 alphabet (RFC 4648, lower-cased) for the human-typable bio code. */
    private const TOKEN_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    private const TOKEN_TTL_HOURS = 72;

    public function __construct(private readonly ProfileBioFetcher $bioFetcher) {}

    /**
     * Method A — automatic OAuth match. Verifies instantly when the caller has a
     * linked platform account on the influencer's platform whose handle matches.
     * Influencers carry no `external_user_id`, so we match on (platform, handle);
     * both handle columns are citext, so the comparison is case-insensitive with
     * no manual lower() (which would defeat the index).
     */
    public function claimViaOAuth(Influencer $influencer, User $user): InfluencerClaim
    {
        $linked = PlatformAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', $influencer->platform)
            ->where('handle', $influencer->handle)
            ->exists();

        if (! $linked) {
            throw ClaimException::handleMismatch($influencer->platform);
        }

        return $this->verify($influencer, $user, ClaimMethod::Oauth);
    }

    /**
     * Method B, step 1 — issue a one-time bio code with a 72h expiry. Replaces any
     * prior pending code for this (user, influencer) pair.
     */
    public function issueBioCode(Influencer $influencer, User $user): InfluencerClaim
    {
        return InfluencerClaim::updateOrCreate(
            ['influencer_id' => $influencer->id, 'user_id' => $user->id],
            [
                'method' => ClaimMethod::BioCode,
                'status' => ClaimStatus::Pending,
                'token' => $this->generateToken(),
                'reason' => null,
                'expires_at' => now()->addHours(self::TOKEN_TTL_HOURS),
                'reviewed_by_user_id' => null,
            ],
        );
    }

    /**
     * Method B, step 2 — fetch the public bio and verify the code is present.
     * Distinguishes a missing code (token_not_found, keeps the claim pending for
     * retry) from a transient fetch failure (profile_unavailable, "try again")
     * so a flaky scrape never burns the token.
     */
    public function verifyBioCode(Influencer $influencer, User $user): InfluencerClaim
    {
        $claim = InfluencerClaim::query()
            ->where('influencer_id', $influencer->id)
            ->where('user_id', $user->id)
            ->where('method', ClaimMethod::BioCode)
            ->where('status', ClaimStatus::Pending)
            ->first();

        if ($claim === null || $claim->token === null) {
            throw ClaimException::reason('no_pending_claim', 'Request a verification code before verifying.');
        }

        if ($claim->isExpired()) {
            throw ClaimException::reason('token_expired', 'Your verification code expired. Request a new one.');
        }

        $bio = $this->bioFetcher->fetchProfileBio($influencer->platform, $influencer->handle);
        if ($bio === null) {
            throw ClaimException::reason('profile_unavailable', "Couldn't read your {$influencer->platform->label()} profile right now. Try again in a moment.");
        }

        if (! str_contains(mb_strtolower($bio), mb_strtolower($claim->token))) {
            throw ClaimException::reason('token_not_found', 'The verification code is not in your bio yet. Add it and try again.');
        }

        return $this->verify($influencer, $user, ClaimMethod::BioCode);
    }

    /**
     * The verification transaction shared by both methods. The `WHERE
     * claimed_by_user_id IS NULL` conditional update is the row-level, atomic
     * race guard — two users verifying at once cannot both win; the loser 409s
     * (or 200s idempotently if the winner IS them).
     */
    public function verify(Influencer $influencer, User $user, ClaimMethod $method): InfluencerClaim
    {
        return DB::transaction(function () use ($influencer, $user, $method) {
            $claimed = DB::table('influencers')
                ->where('id', $influencer->id)
                ->whereNull('claimed_by_user_id')
                ->update([
                    'claimed_by_user_id' => $user->id,
                    'claimed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                // Lost the race (or a re-claim). Idempotent when the winner is us.
                $current = (int) DB::table('influencers')->where('id', $influencer->id)->value('claimed_by_user_id');
                if ($current !== $user->id) {
                    throw ClaimException::conflict();
                }
            } else {
                User::whereKey($user->id)->update(['is_influencer' => true]);
            }

            $claim = $this->recordVerified($influencer, $user, $method);
            $this->rejectCompetingClaims($influencer, $user);

            if ($claimed !== 0) {
                InfluencerClaimed::dispatch($influencer->refresh(), $user);
            }

            return $claim;
        });
    }

    /**
     * Admin dispute-resolution: approve `$claim`, overriding any existing claimant
     * (06 §5.1 disputes are manual). Clears the previous owner's link and — when
     * they hold no other claimed influencer — their `is_influencer` flag.
     */
    public function approve(InfluencerClaim $claim, User $admin): void
    {
        DB::transaction(function () use ($claim, $admin) {
            /** @var Influencer $influencer */
            $influencer = Influencer::whereKey($claim->influencer_id)->lockForUpdate()->firstOrFail();
            $previousId = $influencer->claimed_by_user_id;

            if ($previousId !== null && $previousId !== $claim->user_id) {
                InfluencerClaim::query()
                    ->where('influencer_id', $influencer->id)
                    ->where('user_id', $previousId)
                    ->update(['status' => ClaimStatus::Rejected, 'reason' => 'admin_override', 'reviewed_by_user_id' => $admin->id, 'updated_at' => now()]);
                $this->demoteIfOrphaned($previousId, $influencer->id);
            }

            $influencer->forceFill(['claimed_by_user_id' => $claim->user_id, 'claimed_at' => now()])->save();
            User::whereKey($claim->user_id)->update(['is_influencer' => true]);

            $claim->forceFill(['status' => ClaimStatus::Verified, 'reason' => null, 'token' => null, 'reviewed_by_user_id' => $admin->id])->save();
            $this->rejectCompetingClaims($influencer, $claim->user, $admin->id);

            /** @var User $user */
            $user = User::findOrFail($claim->user_id);
            InfluencerClaimed::dispatch($influencer->refresh(), $user);
        });
    }

    /** Admin reject: mark the claim rejected and notify the claimant. */
    public function reject(InfluencerClaim $claim, User $admin): void
    {
        $claim->forceFill([
            'status' => ClaimStatus::Rejected,
            'reason' => 'rejected_by_admin',
            'reviewed_by_user_id' => $admin->id,
        ])->save();

        $claim->user->notify(new InfluencerClaimRejected($claim->influencer));
    }

    private function recordVerified(Influencer $influencer, User $user, ClaimMethod $method): InfluencerClaim
    {
        return InfluencerClaim::updateOrCreate(
            ['influencer_id' => $influencer->id, 'user_id' => $user->id],
            ['method' => $method, 'status' => ClaimStatus::Verified, 'token' => null, 'reason' => null, 'expires_at' => null],
        );
    }

    /** Auto-reject every other pending claim on this identity once someone wins it. */
    private function rejectCompetingClaims(Influencer $influencer, User $winner, ?int $reviewedBy = null): void
    {
        InfluencerClaim::query()
            ->where('influencer_id', $influencer->id)
            ->where('user_id', '!=', $winner->id)
            ->where('status', ClaimStatus::Pending)
            ->update(['status' => ClaimStatus::Rejected, 'reason' => 'claimed_by_other', 'reviewed_by_user_id' => $reviewedBy, 'updated_at' => now()]);
    }

    /** Clear a user's influencer flag only if they own no other influencer identity. */
    private function demoteIfOrphaned(int $userId, int $exceptInfluencerId): void
    {
        $ownsOther = Influencer::query()
            ->where('claimed_by_user_id', $userId)
            ->where('id', '!=', $exceptInfluencerId)
            ->exists();

        if (! $ownsOther) {
            User::whereKey($userId)->update(['is_influencer' => false]);
        }
    }

    private function generateToken(): string
    {
        $suffix = '';
        for ($i = 0; $i < 8; $i++) {
            $suffix .= self::TOKEN_ALPHABET[random_int(0, strlen(self::TOKEN_ALPHABET) - 1)];
        }

        return 'reelmap-verify-'.$suffix;
    }
}
