<?php

use App\Mail\VerifyEmailCode;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/** Register a fresh (unverified) account and return its email. */
function registerUnverified(string $email = 'nuevo@example.com'): string
{
    test()->postJson('/api/v1/auth/register', [
        'name' => 'Nuevo',
        'username' => 'nuevo',
        'email' => $email,
        'password' => 'secret123!',
        'device_name' => 'cli',
    ])->assertCreated();

    return $email;
}

it('emails a confirmation code on register and issues a first-session token', function () {
    Mail::fake();

    $res = $this->postJson('/api/v1/auth/register', [
        'name' => 'Nuevo', 'username' => 'nuevo', 'email' => 'nuevo@example.com',
        'password' => 'secret123!', 'device_name' => 'cli',
    ])->assertCreated();

    // Usable this first session, but not yet verified.
    expect($res->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($res->json('data.user.email_verified_at'))->toBeNull();

    Mail::assertQueued(VerifyEmailCode::class, fn ($m) => $m->hasTo('nuevo@example.com'));
    expect(DB::table('email_verification_codes')->where('email', 'nuevo@example.com')->exists())->toBeTrue();
});

it('blocks login for an unverified account with a 403 email_not_verified', function () {
    Mail::fake();
    $user = User::factory()->unverified()->create(['email' => 'sinverif@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'sinverif@example.com', 'password' => 'password', 'device_name' => 'cli',
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'email_not_verified')
        ->assertJsonPath('error.details.email', 'sinverif@example.com');
});

it('verifies with the correct code, sets the timestamp, and returns a token', function () {
    Mail::fake();
    $user = User::factory()->unverified()->create(['email' => 'v@example.com']);
    // Seed a known code the way the service stores it.
    DB::table('email_verification_codes')->insert([
        'email' => 'v@example.com', 'code' => Hash::make('123456'), 'created_at' => now(),
    ]);

    $res = $this->postJson('/api/v1/auth/verify-email', [
        'email' => 'v@example.com', 'code' => '123456', 'device_name' => 'cli',
    ])->assertOk();

    expect($res->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($res->json('data.user.email_verified_at'))->not->toBeNull();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    // Code is single-use.
    expect(DB::table('email_verification_codes')->where('email', 'v@example.com')->exists())->toBeFalse();

    // And now login works.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'v@example.com', 'password' => 'password', 'device_name' => 'cli',
    ])->assertOk();
});

it('rejects a wrong or expired code without leaking account existence', function () {
    Mail::fake();
    User::factory()->unverified()->create(['email' => 'v@example.com']);
    DB::table('email_verification_codes')->insert([
        'email' => 'v@example.com', 'code' => Hash::make('123456'), 'created_at' => now()->subMinutes(20),
    ]);

    // Expired.
    $this->postJson('/api/v1/auth/verify-email', ['email' => 'v@example.com', 'code' => '123456', 'device_name' => 'cli'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.code.0', 'El código es inválido o venció.');

    // Unknown email → same generic error (no oracle).
    $this->postJson('/api/v1/auth/verify-email', ['email' => 'ghost@example.com', 'code' => '000000', 'device_name' => 'cli'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('resends a code, always answering 200 and never enumerating', function () {
    Mail::fake();
    $email = registerUnverified();

    // Age the existing code past the resend throttle so a new one issues.
    DB::table('email_verification_codes')->where('email', $email)->update(['created_at' => now()->subSeconds(120)]);

    $this->postJson('/api/v1/auth/resend-verification', ['email' => $email])
        ->assertOk()->assertJsonPath('data.status', 'sent');

    // A non-existent account still answers 200 (no leak) and sends nothing.
    $this->postJson('/api/v1/auth/resend-verification', ['email' => 'ghost@example.com'])
        ->assertOk()->assertJsonPath('data.status', 'sent');
    Mail::assertQueued(VerifyEmailCode::class, 2); // register + the one resend, not the ghost
});

it('burns a code after too many wrong guesses (brute-force cap)', function () {
    User::factory()->unverified()->create(['email' => 'v@example.com']);
    DB::table('email_verification_codes')->insert([
        'email' => 'v@example.com', 'code' => Hash::make('123456'), 'created_at' => now(),
    ]);
    $service = app(EmailVerificationService::class);

    // Five wrong guesses.
    for ($i = 0; $i < 5; $i++) {
        expect($service->check('v@example.com', '000000'))->toBeFalse();
    }
    // The code is now burned — even the CORRECT code no longer verifies.
    expect($service->check('v@example.com', '123456'))->toBeFalse();
    expect(DB::table('email_verification_codes')->where('email', 'v@example.com')->exists())->toBeFalse();
});

it('throttles rapid resends (no second email within the window)', function () {
    Mail::fake();
    $email = registerUnverified(); // issues code #1

    // Immediately resend — within the 60s window, so no new mail.
    $this->postJson('/api/v1/auth/resend-verification', ['email' => $email])->assertOk();

    Mail::assertQueued(VerifyEmailCode::class, 1);
});
