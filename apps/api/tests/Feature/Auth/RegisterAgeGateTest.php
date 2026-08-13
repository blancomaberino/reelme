<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The signup age gate (T-113).
 *
 * Two claims are under test and they are easy to confuse. One is that an
 * under-age signup is REFUSED. The other — the reason the feature is shaped the
 * way it is — is that the date of birth used to decide it is NEVER STORED. A
 * gate that works while quietly persisting a new identifier for every user
 * would pass every test written for the first claim alone.
 */
it('lets an adult register and records that the check happened', function () {
    $response = $this->postJson('/api/v1/auth/register', registerPayload());

    $response->assertCreated();

    $user = User::where('email', 'maya@example.com')->firstOrFail();
    expect($user->age_verified_at)->not->toBeNull();
});

it('never stores the date of birth it just checked', function () {
    // The point of a neutral age screen. `birthdate` IS in the model's fillable
    // list, so a field named `birthdate` instead of `date_of_birth` would have
    // been mass-assigned straight into the column — this asserts the outcome
    // rather than trusting that naming.
    $this->postJson('/api/v1/auth/register', registerPayload([
        'date_of_birth' => '1994-03-02',
    ]))->assertCreated();

    $row = DB::table('users')->where('email', 'maya@example.com')->first();

    expect($row->birthdate)->toBeNull();
    // And no other column quietly took a copy: no value in the row may contain
    // the date in any representation.
    foreach ((array) $row as $column => $value) {
        if (is_string($value)) {
            expect($value)->not->toContain('1994-03-02')
                ->and($value)->not->toContain('02/03/1994');
        }
    }
});

it('refuses a registration below the minimum age', function () {
    $minimum = (int) config('legal.minimum_age');

    $response = $this->postJson('/api/v1/auth/register', registerPayload([
        'date_of_birth' => now()->subYears($minimum - 1)->toDateString(),
    ]));

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'age_restricted')
        ->assertJsonPath('error.details.minimum_age', $minimum)
        ->assertJsonPath('error.details.field', 'date_of_birth');

    // Refused means refused: no account, and no half-created row to collide
    // with the same username later.
    expect(User::where('email', 'maya@example.com')->exists())->toBeFalse();
});

it('decides the boundary the way a person counts birthdays', function () {
    $minimum = (int) config('legal.minimum_age');

    // The day BEFORE the birthday: still too young, by one day.
    $this->postJson('/api/v1/auth/register', registerPayload([
        'date_of_birth' => now()->subYears($minimum)->addDay()->toDateString(),
    ]))->assertStatus(422)->assertJsonPath('error.code', 'age_restricted');

    // The birthday itself: eligible. This is the assertion that fails if age is
    // ever computed by dividing days by 365.
    $this->postJson('/api/v1/auth/register', registerPayload([
        'date_of_birth' => now()->subYears($minimum)->toDateString(),
    ]))->assertCreated();
});

it('rejects a date in the future instead of computing a negative age', function () {
    $this->postJson('/api/v1/auth/register', registerPayload([
        'date_of_birth' => now()->addYear()->toDateString(),
    ]))->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['date_of_birth']]]);
});

it('requires a date of birth at all', function () {
    // Without `required`, an omitted field would skip the rule entirely and the
    // gate would be bypassed by sending nothing — the most obvious attack on an
    // age screen, and the one a happy-path test never tries.
    $payload = registerPayload();
    unset($payload['date_of_birth']);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['date_of_birth']]]);

    expect(User::where('email', 'maya@example.com')->exists())->toBeFalse();
});

it('rejects an unparseable date without inventing an age complaint', function () {
    $response = $this->postJson('/api/v1/auth/register', registerPayload([
        'date_of_birth' => 'not-a-date',
    ]));

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['date_of_birth']]]);

    // One complaint, about the shape — not a second one telling someone with a
    // typo that they are too young.
    $errors = $response->json('error.details.date_of_birth');
    expect($errors)->toHaveCount(1)
        ->and($errors[0])->not->toContain((string) config('legal.minimum_age'));
});

it('enforces the same age the published terms state', function () {
    /*
     * The binding that makes the gate honest.
     *
     * The terms said "at least 13 years old" for as long as nothing enforced
     * it, so the document and the app were free to disagree — and did. Both now
     * read one config value, and this asserts the two ends against each other
     * rather than against a literal, so raising the minimum cannot update the
     * rule while leaving the prose promising something else.
     */
    config()->set('legal.minimum_age', 16);
    config()->set('legal.controller', 'Test Operator Ltd');
    config()->set('legal.domicile', 'Testville, Testland');
    config()->set('legal.contact_email', 'legal@example.test');

    $this->get('/terms/en')->assertOk()->assertSee('at least <strong>16 years old</strong>', false);
    $this->get('/terms/es')->assertOk()->assertSee('al menos <strong>16 años</strong>', false);

    // …and the gate moves with it: someone who was eligible at 13 is not now.
    $this->postJson('/api/v1/auth/register', registerPayload([
        'date_of_birth' => now()->subYears(14)->toDateString(),
    ]))->assertStatus(422)
        ->assertJsonPath('error.code', 'age_restricted')
        ->assertJsonPath('error.details.minimum_age', 16);
});

it('sends the minimum as data so the client can say it in the user’s language', function () {
    /*
     * Why this is a distinct `age_restricted` code carrying a NUMBER, rather
     * than an ordinary validation message.
     *
     * Nothing in this API calls `App::setLocale()` — `RememberUserLocale` only
     * persists a choice for notifications composed later in a queue worker —
     * so every validation message it has ever returned is English, while the
     * app's default language is Spanish. Telling someone "you're too young" in
     * the wrong language, at the moment they are signing up, is a bad place to
     * be careless.
     *
     * The alternative was mirroring the minimum into the mobile bundle, which
     * is the hand-maintained-mirror trap: the two copies drift the first time
     * the number changes, and nothing fails. So the server states the RULE and
     * the client states it in the user's words.
     */
    $minimum = (int) config('legal.minimum_age');

    $response = $this->postJson('/api/v1/auth/register', registerPayload([
        'date_of_birth' => now()->subYears($minimum - 2)->toDateString(),
    ]));

    // The number the client interpolates, and the field to attach it to.
    $response->assertJsonPath('error.details.minimum_age', $minimum)
        ->assertJsonPath('error.details.field', 'date_of_birth');

    expect($response->json('error.details.minimum_age'))->toBeInt();
});

it('states the minimum in the fallback message too', function () {
    /*
     * "You need to be at least 13" is actionable; "invalid date of birth" is a
     * person guessing at what they did wrong.
     *
     * The message is ENGLISH regardless of Accept-Language, and that is not an
     * oversight of this rule: nothing in this API calls `App::setLocale()` at
     * all — `RememberUserLocale` only persists the user's choice for
     * notifications composed later in a queue worker, and `RequestLocale` is
     * consulted explicitly by the one resource that localizes labels. Every
     * validation message the API has ever returned is English.
     *
     * So the Spanish-speaking user is served by the CLIENT, which validates the
     * same rule before it posts and shows its own localized copy (see
     * app/(auth)/register.tsx). This message is the enforcement's fallback, not
     * the thing people normally read — and a lone localized string here would
     * have implied a localization the rest of the API does not do.
     */
    $minimum = (int) config('legal.minimum_age');

    $response = $this->postJson('/api/v1/auth/register', registerPayload([
        'date_of_birth' => now()->subYears($minimum - 2)->toDateString(),
    ]));

    // Anything that reads the envelope without knowing this code — a log, a
    // curl, a future client — still gets a sentence that names the rule.
    expect($response->json('error.message'))->toContain("at least {$minimum}");
});
