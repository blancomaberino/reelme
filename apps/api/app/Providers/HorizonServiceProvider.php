<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
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
