<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        $this->routeLongWaitNotifications();
    }

    /**
     * Where a `LongWaitDetected` alert goes (T-052).
     *
     * `config/horizon.php`'s `waits` thresholds have been tuned per queue since
     * T-028 — and until now the notification they raise went NOWHERE. Horizon
     * routes nothing by default, so the whole alerting half of that config was
     * dead: the thresholds were correct, the alert fired, and no human was on
     * the other end. Which is indistinguishable from having no thresholds.
     *
     * Both channels are optional and env-backed, so local and CI stay silent —
     * an alert that pages during a test run is an alert somebody turns off.
     *
     * NOTE this covers a BACKLOG, not a failure. A job that exhausts its retries
     * is captured by ObservabilityServiceProvider's `Queue::failing` hook and
     * goes to the error tracker with its share_id — the two are different
     * questions ("is work piling up" vs "did this share break") and deliberately
     * have different destinations.
     */
    private function routeLongWaitNotifications(): void
    {
        if ($email = config('horizon.notifications.mail')) {
            Horizon::routeMailNotificationsTo($email);
        }

        if ($webhook = config('horizon.notifications.slack_webhook')) {
            Horizon::routeSlackNotificationsTo($webhook, config('horizon.notifications.slack_channel'));
        }
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Admins only in all non-local environments (staging/production). Horizon
        // leaves the dashboard open in `local` by its own default (accepted for
        // dev; Sentinel blocks public/tunnel exposure there). `users.is_admin`
        // lands in T-003; guests (null user) are denied.
        Gate::define('viewHorizon', fn (?User $user = null) => (bool) $user?->is_admin);
    }
}
