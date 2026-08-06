<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DashboardPeriod;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Influencer;
use App\Models\User;
use App\Services\Influencers\DashboardMetrics;
use App\Services\Payments\PayoutService;
use App\Services\Payments\StripeConnect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * "Which of my reels actually made money" (T-048, 06 §5.2).
 *
 * The funnel and the money block are deliberately served together: an
 * influencer asking whether a post was worth making needs the visits and the
 * euros in the same glance, and splitting them into two requests would let the
 * two halves disagree on screen while both were individually correct.
 *
 * Gated on holding a CLAIMED influencer identity, not on `is_influencer` alone.
 * The two can differ — `is_influencer` is a user flag, the dashboard is about a
 * specific identity's posts — and an unclaimed identity has no dashboard at all
 * (its earnings sit in escrow until somebody proves they own it, 06 §5.3).
 */
class InfluencerDashboardController extends Controller
{
    /** 06 §5.2 — dashboards are read repeatedly and none of this is live-critical. */
    private const CACHE_SECONDS = 300;

    public function show(
        Request $request,
        DashboardMetrics $metrics,
        PayoutService $payouts,
        StripeConnect $stripe,
    ): JsonResponse {
        $validated = $request->validate([
            'period' => ['nullable', Rule::enum(DashboardPeriod::class)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $influencer = $this->claimedIdentity($user);
        $period = DashboardPeriod::tryFrom((string) ($validated['period'] ?? '')) ?? DashboardPeriod::Last30Days;

        $currency = (string) config('monetization.currency');

        // Only the AGGREGATES are cached. Balances and the Connect status are
        // not: a stale balance is a cash-out button that lies, and 06 §5.2's
        // five minutes is about relieving repeated funnel scans, not about
        // money. The wallet reads these live for the same reason.
        $funnel = Cache::remember(
            "influencer-dashboard:{$influencer->id}:{$period->key()}:{$currency}",
            self::CACHE_SECONDS,
            fn () => $metrics->build($influencer, $period),
        );

        $status = $stripe->accountStatus($user);

        return ApiResponse::item($funnel + [
            'influencer' => [
                'id' => (string) $influencer->id,
                'handle' => $influencer->handle,
                'platform' => $influencer->platform,
            ],
            'money' => [
                'available' => $this->money($payouts->availableBalance($user, $currency), $currency),
                'threshold' => $this->money($payouts->threshold(), $currency),
            ],
            'connect' => [
                'onboarded' => $status->accountId !== null,
                'payouts_enabled' => $status->payoutsEnabled,
            ],
        ]);
    }

    /**
     * The influencer identity this user has proved they own.
     *
     * 403 rather than an empty dashboard: an empty funnel invites "why did my
     * reels earn nothing", which is the wrong question to raise in someone who
     * never had an identity linked in the first place.
     */
    private function claimedIdentity(User $user): Influencer
    {
        $influencer = Influencer::query()->where('claimed_by_user_id', $user->id)->first();

        abort_if($influencer === null, 403, 'This account has no claimed influencer profile.');

        return $influencer;
    }

    /** @return array{amount: int, currency: string} */
    private function money(int $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency];
    }
}
