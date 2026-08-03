<?php

namespace App\Providers;

use App\Services\Payments\FakeStripeConnect;
use App\Services\Payments\StripeConnect;
use App\Services\Payments\StripeConnectClient;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

/**
 * Binds the Stripe Connect driver (T-045).
 *
 * **No secret configured → the fake.** That is the default in tests and on any
 * machine without credentials, and it is not a convenience: CLAUDE.md requires
 * the suite to run with no network, and a payout is not something anyone should
 * be able to fire at a live Stripe account by accident while developing.
 *
 * A singleton so a test can reach for the same instance it configured — the fake
 * carries the account states a test set up, and a fresh one per resolve would
 * silently discard them.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeConnect::class, function (): StripeConnect {
            $secret = config('services.stripe.secret');

            if (! is_string($secret) || $secret === '') {
                return new FakeStripeConnect;
            }

            return new StripeConnectClient(new StripeClient([
                'api_key' => $secret,
                // Pinned: Stripe ties a webhook endpoint to an API version, and
                // an SDK upgrade that moved ours would change payload shapes
                // under handlers that reconcile payouts.
                'stripe_version' => (string) config('services.stripe.api_version'),
            ]));
        });
    }
}
