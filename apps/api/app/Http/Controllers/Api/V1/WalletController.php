<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LedgerAccount;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\PayoutService;
use App\Services\Payments\StripeConnect;
use App\Support\KeysetCursor;
use App\Support\KeysetPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The influencer's money (T-045, 03 §2.14).
 *
 * Every figure here is DERIVED from `ledger_entries` — nothing is stored, so
 * the wallet and the books cannot disagree. `available` is the signed balance,
 * which means a void after a payout (06 §4.4) shows as a negative carried
 * against future earnings rather than being floored at zero and quietly
 * forgotten.
 *
 * The Connect status is read LIVE from Stripe on every call rather than from
 * `stripe_connect_onboarded_at`: Stripe re-verifies, and requirements can
 * reappear months after onboarding. A wallet that shows "ready to cash out"
 * based on a cached flag is a button that fails.
 */
class WalletController extends Controller
{
    private const CURSOR = 'wallet';

    public function show(Request $request, PayoutService $payouts, StripeConnect $stripe): JsonResponse
    {
        $user = $this->user($request);
        $available = $payouts->availableBalance($user);
        $status = $stripe->accountStatus($user);

        return ApiResponse::item([
            'available_minor' => $available,
            'currency' => (string) config('monetization.currency'),
            'payout_threshold_minor' => $payouts->threshold(),
            'can_request_payout' => $available >= $payouts->threshold() && $status->canReceiveTransfers(),
            'connect' => $status->toArray(),
        ]);
    }

    /** The entries behind the balance — an influencer's statement. */
    public function ledger(Request $request, LedgerService $ledger): JsonResponse
    {
        $user = $this->user($request);
        $limit = min(50, max(1, (int) $request->integer('limit', 25)));

        $query = $user->ledgerEntries()
            ->where('account', LedgerAccount::InfluencerEarnings)
            ->orderByDesc('id');

        $cursor = KeysetCursor::decode($request->query('cursor'), self::CURSOR, 1);
        if ($cursor !== null) {
            $query->where('id', '<', KeysetCursor::intKey($cursor[0]));
        }

        $page = KeysetPage::query($query, $limit, self::CURSOR, fn ($last) => [$last->id]);

        return ApiResponse::page($page->items->map(fn ($entry) => [
            'id' => (string) $entry->id,
            'direction' => $entry->direction->value,
            'amount_minor' => $entry->amount,
            'currency' => $entry->currency,
            'memo' => $entry->memo,
            'created_at' => $entry->created_at->toIso8601ZuluString(),
        ]), $page);
    }

    /** Start or refresh hosted onboarding. Never cached — links are single-use. */
    public function onboardingLink(Request $request, StripeConnect $stripe): JsonResponse
    {
        return ApiResponse::item([
            'url' => $stripe->createOnboardingLink($this->user($request)),
        ]);
    }

    public function connectStatus(Request $request, StripeConnect $stripe): JsonResponse
    {
        return ApiResponse::item($stripe->accountStatus($this->user($request))->toArray());
    }

    /** Cash out the full available balance. */
    public function requestPayout(Request $request, PayoutService $payouts): JsonResponse
    {
        $payout = $payouts->request($this->user($request));

        return ApiResponse::item($this->payoutPayload($payout), [], 201);
    }

    public function payouts(Request $request): JsonResponse
    {
        $rows = Payout::query()
            ->where('user_id', $this->user($request)->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return ApiResponse::collection($rows->map(fn (Payout $p) => $this->payoutPayload($p)));
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutPayload(Payout $payout): array
    {
        return [
            'id' => (string) $payout->id,
            'amount_minor' => $payout->amount,
            'currency' => $payout->currency,
            'status' => $payout->status->value,
            'period_start' => $payout->period_start->toDateString(),
            'period_end' => $payout->period_end->toDateString(),
            'failure_reason' => $payout->failure_reason,
            'paid_at' => $payout->paid_at?->toIso8601ZuluString(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
