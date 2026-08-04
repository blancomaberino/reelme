<?php

use App\Models\User;
use App\Support\RequestLocale;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

/**
 * How `users.locale` gets set, and — just as importantly — when it doesn't.
 *
 * A push is written in a queued worker with no request in scope, so the
 * recipient's language has to be a stored fact. The mobile client already sends
 * `Accept-Language` on every call, so the column is captured from that rather
 * than from a new endpoint the client must remember to call.
 *
 * The dangerous half is the negative case: `RequestLocale::resolve()` falls back
 * to the app default (`en`), so persisting IT would mean one header-less
 * request — a curl, an older build, a webhook replay — silently flipping a
 * Spanish-speaking user's account to English and sending them English pushes
 * from then on.
 */
it('records the language the client is running in', function () {
    $user = User::factory()->create(['locale' => 'es']);
    Sanctum::actingAs($user);

    $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/notifications')->assertOk();

    expect($user->fresh()->locale)->toBe('en');
});

it('follows the language toggle on the next request', function () {
    $user = User::factory()->create(['locale' => 'en']);
    Sanctum::actingAs($user);

    $this->withHeader('Accept-Language', 'es-419,es;q=0.9')->getJson('/api/v1/notifications')->assertOk();

    // Region subtag dropped — the account speaks `es`, not `es-419`.
    expect($user->fresh()->locale)->toBe('es');
});

it('leaves the stored language alone when the client expresses no preference', function () {
    $user = User::factory()->create(['locale' => 'es']);
    Sanctum::actingAs($user);

    // Explicitly EMPTY rather than absent: Symfony's `Request::create()` — which
    // every test request goes through — injects a default
    // `Accept-Language: en-us,en;q=0.5`, so "omit the header" is not something
    // this layer can express. The unit assertion below covers a genuinely
    // header-less request.
    $this->withHeader('Accept-Language', '')->getJson('/api/v1/notifications')->assertOk();

    expect($user->fresh()->locale)->toBe('es');
});

it('reports no preference for a request that carries no language at all', function () {
    // The production shape of the case above: a curl, a webhook replay, an
    // older build. `resolve()` answers `en` here (the app default) and
    // persisting THAT is what would flip a Spanish account to English — hence
    // the middleware asking `explicit()` instead.
    $bare = Request::create('/api/v1/notifications');
    // `Request::create()` seeds `Accept-Language: en-us,en;q=0.5` of its own
    // accord, so it has to come back off to model a client that sent none.
    $bare->headers->remove('Accept-Language');

    expect(RequestLocale::explicit($bare))->toBeNull()
        ->and(RequestLocale::resolve($bare))->toBe('en');
});

it('ignores a language the app does not ship', function () {
    $user = User::factory()->create(['locale' => 'es']);
    Sanctum::actingAs($user);

    $this->withHeader('Accept-Language', 'de-DE')->getJson('/api/v1/notifications')->assertOk();

    expect($user->fresh()->locale)->toBe('es');
});

it('falls back to Spanish for a locale outside the shipped set', function () {
    // A stale row, or a column written before the allowlist existed. Resolving
    // it verbatim would look up a translation file that isn't there and render
    // the raw dotted key to the user.
    $user = User::factory()->make(['locale' => 'pt']);

    expect($user->preferredLocale())->toBe('es');
});

it('honours a supported stored locale', function () {
    expect(User::factory()->make(['locale' => 'en'])->preferredLocale())->toBe('en')
        ->and(User::factory()->make(['locale' => 'es'])->preferredLocale())->toBe('es');
});
