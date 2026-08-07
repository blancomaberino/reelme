<?php

use App\Enums\PayoutStatus;
use App\Jobs\Gdpr\ExportUserData;
use App\Jobs\Gdpr\PurgeUserData;
use App\Models\Payout;
use App\Models\PlatformAccount;
use App\Models\User;
use App\Services\Gdpr\AccountDeletion;
use App\Services\Gdpr\UserDataPurger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * DELETE /me — the request side of erasure (T-050, NFR-10).
 *
 * The thing worth testing here is not that a row gets a `deleted_at`. It is
 * that the two halves of "deleted" happen at the right times: the session dies
 * NOW, the data dies LATER, and the window in between is genuinely reversible
 * without being a way back into an account that is already gone.
 */
beforeEach(function () {
    Queue::fake();
});

it('ends every session immediately and schedules the purge', function () {
    $user = User::factory()->create();
    $token = $user->createToken('phone')->plainTextToken;
    $user->createToken('tablet');

    $response = $this->withToken($token)->deleteJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.grace_days', config('gdpr.purge_grace_days'))
        // The date the app SHOWS the user. Returning `now()` here would pass
        // every other assertion in this file while telling somebody their
        // account dies today.
        ->assertJsonPath(
            'data.purge_at',
            now()->addDays((int) config('gdpr.purge_grace_days'))->toIso8601String(),
        );

    expect($user->fresh()->trashed())->toBeTrue()
        // BOTH tokens, not just the calling one. A deletion that left the
        // tablet signed in would be a deletion in name only.
        ->and($user->tokens()->count())->toBe(0);

    // And the credential really is dead on the NEXT request, not merely absent
    // from a table — the middleware has to agree. forgetGuards first: the
    // sanctum guard caches its resolved user for the life of the container, and
    // in a test that container spans both requests, so without this the second
    // call is answered from the first one's memory and passes no matter what.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

    Queue::assertPushed(PurgeUserData::class, fn ($job) => $job->userId === $user->id);
});

it('delays the purge by the configured grace period', function () {
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();

    Queue::assertPushed(PurgeUserData::class, function ($job) {
        // The irreversible half must not be able to run today. Without a delay
        // the job is picked up within seconds and the grace period is a lie
        // told by the response body.
        return $job->delay?->greaterThan(now()->addDays(13)) === true;
    });
});

it('does not queue a second purge when asked twice', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();
    // Same user, already trashed — the second call must be a no-op rather than
    // restarting the clock or stacking another erasure.
    app(AccountDeletion::class)->request($user->fresh());

    Queue::assertPushed(PurgeUserData::class, 1);
});

it('lets the owner sign back in during the grace period, cancelling the deletion', function () {
    $user = User::factory()->create(['email' => 'regret@test.dev', 'password' => bcrypt('secret-password')]);

    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();
    expect($user->fresh()->trashed())->toBeTrue();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'regret@test.dev',
        'password' => 'secret-password',
        'device_name' => 'phone',
    ]);

    $response->assertOk()->assertJsonStructure(['data' => ['token']]);
    expect($user->fresh()->trashed())->toBeFalse();
});

it('refuses the login once the grace period has lapsed', function () {
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create(['email' => 'gone@test.dev', 'password' => bcrypt('secret-password')]);

    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();
    // Past the window: the purge is due or done, so "signing back in" would
    // restore a shell of an account whose data no longer exists.
    $user->forceFill([
        'deleted_at' => now()->subDays(15),
        'deletion_requested_at' => now()->subDays(15),
    ])->saveQuietly();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'gone@test.dev',
        'password' => 'secret-password',
        'device_name' => 'phone',
    ])->assertStatus(422);

    expect($user->fresh()->trashed())->toBeTrue();
});

it('skips a purge whose deletion was cancelled', function () {
    $user = User::factory()->create();
    app(AccountDeletion::class)->request($user);
    app(AccountDeletion::class)->cancel($user->fresh());

    // The queued job cannot be recalled once dispatched, so the guard inside it
    // is the only thing standing between a cancelled deletion and an erased
    // account. Run the real job to prove that guard holds.
    (new PurgeUserData($user->id))->handle(
        app(UserDataPurger::class),
        app(AccountDeletion::class),
    );

    $user->refresh();
    expect($user->trashed())->toBeFalse()
        ->and($user->email)->not->toContain('reelmap.invalid');
});

it('does not let a BANNED account sign itself back in', function () {
    // A ban is also a soft delete. When the grace period first shipped it keyed
    // on `deleted_at` alone, which made "sign back in to cancel your deletion"
    // into a self-service unban — caught by the existing ban suite, not by any
    // test written for this feature. `deletion_requested_at` is what separates
    // the two states, and this is the case that keeps them separate.
    $user = User::factory()->create(['email' => 'banned@test.dev', 'password' => bcrypt('secret-password')]);
    $user->tokens()->delete();
    $user->delete();

    expect($user->fresh()->deletion_requested_at)->toBeNull();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'banned@test.dev',
        'password' => 'secret-password',
        'device_name' => 'phone',
    ])->assertStatus(422);

    expect(User::withTrashed()->find($user->id)->trashed())->toBeTrue();
});

it('does not purge a banned account', function () {
    // Same conflation, opposite direction: a ban is a moderation decision, not
    // a request to be erased. Running the purge on one would destroy the very
    // evidence the ban was based on.
    $user = User::factory()->create(['name' => 'Banned Person']);
    $user->delete();

    (new PurgeUserData($user->id))->handle(
        app(UserDataPurger::class),
        app(AccountDeletion::class),
    );

    expect(User::withTrashed()->find($user->id)->name)->toBe('Banned Person');
});

it('rejects both data-rights endpoints for a caller with no session', function () {
    $this->deleteJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/api/v1/me/export')->assertUnauthorized();
});

it('actually erases the account when the job runs after the grace period', function () {
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create();
    PlatformAccount::factory()->for($user)->create();

    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();
    $user->forceFill([
        'deleted_at' => now()->subDays(15),
        'deletion_requested_at' => now()->subDays(15),
    ])->saveQuietly();

    (new PurgeUserData($user->id))->handle(app(UserDataPurger::class), app(AccountDeletion::class));

    // Until this existed, the entire body of handle() could be replaced with
    // `return;` and every test in the suite stayed green: the job was only ever
    // driven through its two NEGATIVE paths, so nothing proved the erasure half
    // of the feature happened at all.
    expect(DB::table('users')->find($user->id)->email)->toEndWith('@reelmap.invalid')
        ->and(PlatformAccount::where('user_id', $user->id)->count())->toBe(0);
});

it('erases nothing when the purge arrives before the grace period is up', function () {
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create();
    $user->forceFill(['deletion_requested_at' => now()])->saveQuietly();
    $user->delete();

    (new PurgeUserData($user->id))->handle(app(UserDataPurger::class), app(AccountDeletion::class));

    // The job must NOT re-dispatch itself here. Under a `sync` connection —
    // which the test env uses, and which a dev box easily has — a self-queueing
    // job runs immediately, finds itself early again, and recurses until the
    // stack gives out. The hourly sweep is what guarantees the account is not
    // forgotten; this only has to be harmless.
    expect(DB::table('users')->find($user->id)->email)->not->toContain('reelmap.invalid');
});

it('puts both jobs on the housekeeping queue', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/me/export')->assertStatus(202);
    $this->actingAs($user)->deleteJson('/api/v1/me')->assertOk();

    // A queue no supervisor listens to accepts every job and runs none of them
    // — an erasure that never happens, reported as success.
    Queue::assertPushed(ExportUserData::class, fn ($job) => $job->queue === 'housekeeping');
    Queue::assertPushed(PurgeUserData::class, fn ($job) => $job->queue === 'housekeeping');
});

it('sweeps up an overdue deletion whose queued job was lost', function () {
    Queue::fake();
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create();
    app(AccountDeletion::class)->request($user);
    $user->forceFill([
        'deleted_at' => now()->subDays(20),
        'deletion_requested_at' => now()->subDays(20),
    ])->saveQuietly();

    Queue::fake();
    $this->artisan('reelmap:gdpr:sweep-deletions')->assertSuccessful();

    // Fourteen days is a long time to trust one row in Redis. A flush, a
    // horizon:clear or a failed job all end as an erasure that silently never
    // happens — and nothing else in the system would ever report it.
    Queue::assertPushed(PurgeUserData::class, fn ($job) => $job->userId === $user->id);
});

it('does not sweep a deletion still inside its grace period', function () {
    Queue::fake();
    $user = User::factory()->create();
    app(AccountDeletion::class)->request($user);

    Queue::fake();
    $this->artisan('reelmap:gdpr:sweep-deletions')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('does not sweep a banned account', function () {
    Queue::fake();
    $user = User::factory()->create();
    $user->delete();
    $user->forceFill(['deleted_at' => now()->subDays(90)])->saveQuietly();

    $this->artisan('reelmap:gdpr:sweep-deletions')->assertSuccessful();

    // A ban is a moderation decision with no clock on it. Sweeping one would
    // erase the evidence it rests on.
    Queue::assertNothingPushed();
});

it('keeps the grace period the mobile copy promises', function () {
    // apps/mobile/app/settings/privacy.tsx hard-codes DELETION_GRACE_DAYS = 14
    // into the sentence "sign back in within N days". It cannot read this
    // config — the note renders before any request is made — so the two are
    // pinned here instead. Changing the server default without changing that
    // constant would make the most legally consequential sentence in the app
    // quietly false.
    expect((int) config('gdpr.purge_grace_days'))->toBe(14);
});

it('does not re-purge an account it has already erased', function () {
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create();
    app(AccountDeletion::class)->request($user);
    $user->forceFill([
        'deleted_at' => now()->subDays(20),
        'deletion_requested_at' => now()->subDays(20),
    ])->saveQuietly();

    app(UserDataPurger::class)->purge(User::withTrashed()->find($user->id));
    expect(DB::table('users')->find($user->id)->purged_at)->not->toBeNull();

    Queue::fake();
    $this->artisan('reelmap:gdpr:sweep-deletions')->assertSuccessful();

    // Without the completion marker, every account ever erased still matches
    // `trashed + requested + past grace` and gets a full re-purge on EVERY
    // hourly run — unbounded, growing by one table walk per deletion, and
    // completely silent because the work is idempotent.
    Queue::assertNothingPushed();
});

it('still revisits a purged account that is holding a Stripe linkage', function () {
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create(['stripe_connect_account_id' => 'acct_1']);
    app(AccountDeletion::class)->request($user);
    $user->forceFill([
        'deleted_at' => now()->subDays(20),
        'deletion_requested_at' => now()->subDays(20),
    ])->saveQuietly();
    Payout::factory()->create([
        'user_id' => $user->id,
        'status' => PayoutStatus::Processing,
    ]);

    app(UserDataPurger::class)->purge(User::withTrashed()->find($user->id));

    Queue::fake();
    $this->artisan('reelmap:gdpr:sweep-deletions')->assertSuccessful();

    // The one purge that is finished but still OWES work: the Stripe id was
    // held back for money in flight, and nothing else would ever come back
    // for it once the transfer settles.
    Queue::assertPushed(PurgeUserData::class, fn ($job) => $job->userId === $user->id);
});

it('clears the deletion clock when an admin restores the account', function () {
    $user = User::factory()->create();
    app(AccountDeletion::class)->request($user);

    // The Filament unban path: a plain restore(), with no knowledge of the GDPR
    // columns. If the flag survived it, a later ban would be readable as a
    // pending deletion — and the banned user could sign in and undo it.
    User::withTrashed()->find($user->id)->restore();

    $restored = User::find($user->id);
    expect($restored->deletion_requested_at)->toBeNull()
        ->and($restored->purged_at)->toBeNull()
        ->and($restored->trashed())->toBeFalse();
});
