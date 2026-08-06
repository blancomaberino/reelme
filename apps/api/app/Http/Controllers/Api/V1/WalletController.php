<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LedgerAccount;
use App\Enums\LedgerDirection;
use App\Enums\PayoutStatus;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletEntryResource;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payments\PayoutService;
use App\Services\Payments\StripeConnect;
use App\Support\KeysetCursor;
use App\Support\KeysetPage;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The influencer's (and restaurant owner's) money (T-046, 03 §2.14/§3.5).
 *
 * Every figure is DERIVED from `ledger_entries` — there is no cached balance
 * anywhere, so the wallet and the books cannot disagree. The Connect status is
 * read LIVE from Stripe rather than from `stripe_connect_onboarded_at`, because
 * Stripe re-verifies and requirements reappear: a wallet that says "ready to
 * cash out" from a cached flag is a button that fails.
 *
 * Gated to users who can actually hold money (`is_influencer` or
 * `is_restaurant_owner`). 403 rather than an empty wallet — an empty one invites
 * "where are my earnings", which is the wrong question to prompt in someone who
 * was never going to have any.
 */
class WalletController extends Controller
{
    private const CURSOR = 'wallet';

    /** How many entries ride along on the wallet payload (03 §3.5). */
    private const RECENT_ENTRIES = 5;

    public function show(Request $request, PayoutService $payouts, StripeConnect $stripe): JsonResponse
    {
        $user = $this->eligibleUser($request);
        $currency = (string) config('monetization.currency');

        $status = $stripe->accountStatus($user);
        $available = $payouts->availableBalance($user, $currency);

        $payload = [
            'balance' => [
                // Cashable right now: the signed ledger balance, which already
                // excludes anything an in-flight payout is holding (the hold
                // debits this account the moment one is requested).
                'available' => $this->money($available, $currency),
                'pending' => $this->money($this->pendingBalance($user, $currency), $currency),
            ],
            'lifetime_earnings' => $this->money($this->lifetimeEarnings($user, $currency), $currency),
            'connect' => [
                'onboarded' => $status->payoutsEnabled,
                'payouts_enabled' => $status->payoutsEnabled,
                'requirements_due' => $status->requirementsDue,
            ],
            'minimum_payout' => $this->money($payouts->threshold(), $currency),
            'recent_entries' => WalletEntryResource::collection(
                $this->entriesQuery($user)->limit(self::RECENT_ENTRIES)->get()
            ),
            // Computed here rather than left to the client: the rule is "enough
            // money AND Stripe will accept it", and a client deriving it from
            // the two fields above would drift the day either changes.
            'can_request_payout' => $available >= $payouts->threshold() && $status->canReceiveTransfers(),
        ];

        // A restaurant owner sees what they OWE, not what they earned. Read-only
        // in M4 — invoicing ships late in the phase (06 §7) — but the number has
        // to be visible before anyone can believe an invoice built on it.
        if ($user->is_restaurant_owner) {
            $payload['fees_owed'] = $this->money($this->feesOwed($user, $currency), $currency);
        }

        return ApiResponse::item($payload);
    }

    /** The statement behind the balance. Only ever the caller's own rows. */
    public function ledger(Request $request): JsonResponse
    {
        $user = $this->eligibleUser($request);
        $limit = min(50, max(1, (int) $request->integer('limit', 25)));

        $query = $this->entriesQuery($user);

        $cursor = KeysetCursor::decode($request->query('cursor'), self::CURSOR, 1);
        if ($cursor !== null) {
            $query->where('id', '<', KeysetCursor::intKey($cursor[0]));
        }

        $page = KeysetPage::query($query, $limit, self::CURSOR, fn (LedgerEntry $last) => [$last->id]);

        return ApiResponse::page(WalletEntryResource::collection($page->items), $page);
    }

    public function onboardingLink(Request $request, StripeConnect $stripe): JsonResponse
    {
        // Never cached: account links expire in minutes and are single-use, so
        // "create or refresh" has to genuinely mint one every call.
        return ApiResponse::item([
            'url' => $stripe->createOnboardingLink($this->eligibleUser($request)),
        ]);
    }

    public function connectStatus(Request $request, StripeConnect $stripe): JsonResponse
    {
        $status = $stripe->accountStatus($this->eligibleUser($request));

        return ApiResponse::item([
            'onboarded' => $status->payoutsEnabled,
            'payouts_enabled' => $status->payoutsEnabled,
            'details_submitted' => $status->detailsSubmitted,
            'requirements_due' => $status->requirementsDue,
        ]);
    }

    /**
     * Cash out the whole available balance.
     *
     * Honours `Idempotency-Key` (03 §1). A phone on a bad connection retries a
     * request it never saw the answer to, and without this that retry is a
     * second payout — or, once the hold has landed, a confusing
     * "insufficient balance" for money the user can plainly see. The key maps to
     * the payout the first call produced and returns it.
     */
    public function requestPayout(Request $request, PayoutService $payouts): JsonResponse
    {
        $user = $this->eligibleUser($request);
        $key = $request->header('Idempotency-Key');
        $key = is_string($key) && $key !== '' ? $key : null;

        if ($key !== null) {
            $existing = Payout::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing !== null) {
                return ApiResponse::item($this->payoutPayload($existing), ['replayed' => true]);
            }
        }

        return ApiResponse::item($this->payoutPayload($payouts->request($user, idempotencyKey: $key)), [], 201);
    }

    public function payouts(Request $request): JsonResponse
    {
        $rows = Payout::query()
            ->where('user_id', $this->eligibleUser($request)->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return ApiResponse::collection($rows->map(fn (Payout $p) => $this->payoutPayload($p)));
    }

    /**
     * Money committed to payouts Stripe has not settled.
     *
     * Summed from this user's PAYOUTS rather than from `payout_clearing`, which
     * is a platform-wide account: one influencer must never see another's
     * in-flight money as their own pending balance.
     */
    private function pendingBalance(User $user, string $currency): int
    {
        return (int) Payout::query()
            ->where('user_id', $user->id)
            ->where('currency', $currency)
            ->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Processing])
            ->sum('amount');
    }

    /**
     * Everything ever earned — CREDITS only, so a payout does not reduce it.
     * "Lifetime earnings" is what you made, not what you still hold.
     */
    private function lifetimeEarnings(User $user, string $currency): int
    {
        return (int) LedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('account', LedgerAccount::InfluencerEarnings)
            ->where('currency', $currency)
            ->where('direction', LedgerDirection::Credit)
            ->sum('amount');
    }

    /**
     * What this operator's venues owe (06 §2.3, invoiced monthly).
     *
     * `restaurant_receivable` carries no `user_id` — the debt is the VENUE's,
     * not a person's — so it is scoped through the redemptions at places this
     * user holds a verified claim on. Which also means it follows the claim: an
     * operator who loses a venue stops seeing its debt.
     */
    private function feesOwed(User $user, string $currency): int
    {
        $normal = LedgerAccount::RestaurantReceivable->normalDirection()->value;

        return (int) LedgerEntry::query()
            ->where('account', LedgerAccount::RestaurantReceivable)
            ->where('currency', $currency)
            ->where('reference_type', 'redemption')
            ->whereIn('reference_id', DB::table('redemptions')
                ->select('redemptions.id')
                ->join('offers', 'offers.id', '=', 'redemptions.offer_id')
                ->whereIn('offers.place_id', DB::table('place_claims')
                    ->select('place_id')
                    ->where('user_id', $user->id)
                    ->where('status', 'verified')))
            ->toBase()
            ->selectRaw('coalesce(sum(case when direction = ? then amount else -amount end), 0) AS balance', [$normal])
            ->value('balance');
    }

    /**
     * @return Builder<LedgerEntry>
     */
    private function entriesQuery(User $user): Builder
    {
        return LedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('account', LedgerAccount::InfluencerEarnings)
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutPayload(Payout $payout): array
    {
        return [
            'id' => (string) $payout->id,
            'amount' => $payout->amount,
            'currency' => $payout->currency,
            'status' => $payout->status->value,
            'period_start' => $payout->period_start->toDateString(),
            'period_end' => $payout->period_end->toDateString(),
            'failure_reason' => $payout->failure_reason,
            'paid_at' => $payout->paid_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Money on the wire is always `{amount, currency}` (03 §3.5) — an integer in
     * minor units, and the unit it is in. A bare number is the shape that gets
     * read as euros somewhere downstream.
     *
     * @return array{amount: int, currency: string}
     */
    private function money(int $amount, string $currency): array
    {
        return Money::minor($amount, $currency);
    }

    private function eligibleUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->is_influencer || $user->is_restaurant_owner, 403, 'This account does not have a wallet.');

        return $user;
    }
}
