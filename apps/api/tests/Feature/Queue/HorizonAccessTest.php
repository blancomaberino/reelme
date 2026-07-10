<?php

use App\Models\User;

// APP_ENV=testing (not local), so the viewHorizon gate is enforced.

it('denies the Horizon dashboard to guests', function () {
    // Not authenticated → gate denies (never the local-open default).
    $this->get('/horizon')->assertStatus(403);
});

it('denies the Horizon dashboard to non-admin users', function () {
    $this->actingAs(User::factory()->create()); // is_admin defaults to false

    $this->get('/horizon')->assertStatus(403);
});

it('allows the Horizon dashboard for admin users', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/horizon')->assertOk();
});

it('gates the Horizon JSON data API to non-admins (not just the shell)', function () {
    // The API endpoints (e.g. failed-job payloads) share the same middleware
    // stack; assert a data endpoint directly so route-specific drift is caught.
    $this->actingAs(User::factory()->create());

    $this->getJson('/horizon/api/stats')->assertStatus(403);
});
