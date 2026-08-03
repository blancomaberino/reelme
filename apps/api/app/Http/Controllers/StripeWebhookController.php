<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\Payments\PayoutService;
use App\Services\Payments\StripeConnect;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * The Stripe webhook endpoint (T-045, 03 §4.1).
 *
 * Public by necessity — Stripe has no bearer token — so the SIGNATURE is the
 * only thing standing between this route and an unauthenticated way to mark
 * payouts paid. Two consequences run through the whole class:
 *
 * 1. **Verify before parsing.** The signature covers the RAW body; decoding
 *    JSON first and re-encoding it changes bytes and the signature never
 *    matches. `$request->getContent()` is used for exactly this reason.
 * 2. **Record before acting.** The `stripe_events` unique insert happens before
 *    any side effect, so a redelivery — Stripe retries on our 5xx, on timeouts,
 *    and sometimes unprompted — loses to the index and stops before it can move
 *    money twice.
 *
 * Always answers 200 once an event is stored, even if the handler decides to do
 * nothing. A non-2xx tells Stripe to retry, and retrying an event we understood
 * and deliberately ignored is a loop.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PayoutService $payouts,
        StripeConnect $stripe,
    ): JsonResponse {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            // Not configured: refuse rather than accept unverified events. An
            // endpoint that trusts anything because it cannot check is worse
            // than one that is switched off.
            Log::error('stripe.webhook_secret_missing');

            return response()->json(['error' => ['code' => 'not_configured']], 503);
        }

        try {
            $event = Webhook::constructEvent(
                // RAW body — see the class docblock.
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $secret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('stripe.webhook_signature_invalid', ['message' => $e->getMessage()]);

            return response()->json(['error' => ['code' => 'invalid_signature']], 400);
        }

        try {
            // Wrapped in its own transaction so the unique violation rolls back
            // to a SAVEPOINT. In Postgres a failed statement aborts the whole
            // transaction, so without this a duplicate would poison anything
            // running around it — and this handler must be safe to call from
            // inside an outer transaction, which is exactly what a test suite
            // (and one day a batch replay tool) does.
            $record = DB::transaction(fn () => StripeEvent::query()->create([
                'stripe_event_id' => $event->id,
                'type' => $event->type,
                'payload' => $event->toArray(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Already seen. 200 so Stripe stops retrying.
            return response()->json(['data' => ['status' => 'duplicate']]);
        }

        $this->dispatchEvent($record, $payouts, $stripe);

        $record->forceFill(['processed_at' => now()])->save();

        return response()->json(['data' => ['status' => 'processed']]);
    }

    private function dispatchEvent(StripeEvent $record, PayoutService $payouts, StripeConnect $stripe): void
    {
        match ($record->type) {
            'account.updated' => $this->syncAccount($record, $stripe),
            // Transfer success is the practical v1 trigger for "paid": it is the
            // movement WE initiate. Stripe's own `payout.*` events on an Express
            // account describe Stripe→bank, which is the influencer's bank's
            // business and can lag by days.
            'transfer.created', 'transfer.paid' => $this->markPaid($record, $payouts),
            'transfer.failed', 'transfer.reversed', 'payout.failed' => $this->markFailed($record, $payouts),
            default => Log::info('stripe.webhook_ignored', ['type' => $record->type, 'id' => $record->stripe_event_id]),
        };
    }

    /**
     * Re-read the account from Stripe rather than trusting the payload.
     *
     * The event says what changed; the API says what is true NOW. Between the
     * two, out-of-order delivery can make a stale payload undo a newer one — and
     * this flag is what gates every transfer.
     */
    private function syncAccount(StripeEvent $record, StripeConnect $stripe): void
    {
        $accountId = $record->object()['id'] ?? null;

        if (! is_string($accountId)) {
            return;
        }

        $user = User::query()->where('stripe_connect_account_id', $accountId)->first();

        if ($user === null) {
            Log::warning('stripe.account_updated_unknown_account', ['account_id' => $accountId]);

            return;
        }

        $status = $stripe->accountStatus($user);

        $user->forceFill([
            // Records that onboarding COMPLETED once — never used as the
            // transfer gate, which always re-reads `payouts_enabled` live.
            'stripe_connect_onboarded_at' => $status->payoutsEnabled
                ? ($user->stripe_connect_onboarded_at ?? now())
                : $user->stripe_connect_onboarded_at,
        ])->save();
    }

    private function markPaid(StripeEvent $record, PayoutService $payouts): void
    {
        $payout = $this->payoutFor($record);

        if ($payout !== null) {
            $payouts->markPaid($payout);
        }
    }

    private function markFailed(StripeEvent $record, PayoutService $payouts): void
    {
        $payout = $this->payoutFor($record);

        if ($payout === null) {
            return;
        }

        if ($payout->status->isTerminal()) {
            // A failure for something already settled is a disagreement between
            // Stripe and us, not something to apply — releasing a hold twice
            // would credit money that was never lost.
            Log::error('stripe.terminal_payout_failure_event', [
                'payout_id' => $payout->id,
                'status' => $payout->status->value,
                'event' => $record->stripe_event_id,
            ]);

            return;
        }

        $object = $record->object();
        $reason = $object['failure_message'] ?? $object['failure_code'] ?? 'Stripe reported a failure.';

        $payouts->fail($payout, is_string($reason) ? $reason : 'Stripe reported a failure.');
    }

    /**
     * The payout an event refers to.
     *
     * Matched on our own metadata first — we set `reelmap_payout_id` on every
     * transfer — and on the transfer id second, so an event that predates the
     * metadata still resolves.
     */
    private function payoutFor(StripeEvent $record): ?Payout
    {
        $object = $record->object();
        $payoutId = $object['metadata']['reelmap_payout_id'] ?? null;

        if (is_string($payoutId) || is_int($payoutId)) {
            $payout = Payout::query()->find((int) $payoutId);

            if ($payout !== null) {
                return $payout;
            }
        }

        $transferId = $object['id'] ?? null;

        if (! is_string($transferId)) {
            return null;
        }

        return Payout::query()->where('stripe_transfer_id', $transferId)->first();
    }
}
