<?php

use App\Mail\FriendInvite;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

it('emails each invited address and records the invitation', function () {
    Mail::fake();
    Sanctum::actingAs(User::factory()->create(['name' => 'Marce']));

    $this->postJson('/api/v1/invites', ['emails' => ['a@example.com', 'b@example.com']])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued');

    Mail::assertQueued(FriendInvite::class, 2);
    Mail::assertQueued(FriendInvite::class, fn ($m) => $m->hasTo('a@example.com') && $m->inviterName === 'Marce');
    expect(Invitation::count())->toBe(2);
});

it('silently skips an address that already belongs to a user (no enumeration)', function () {
    Mail::fake();
    User::factory()->create(['email' => 'member@example.com']);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/invites', ['emails' => ['member@example.com', 'new@example.com']])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued'); // uniform response, no per-email breakdown

    Mail::assertQueued(FriendInvite::class, 1);
    Mail::assertQueued(FriendInvite::class, fn ($m) => $m->hasTo('new@example.com'));
    Mail::assertNotQueued(FriendInvite::class, fn ($m) => $m->hasTo('member@example.com'));
});

it('does not re-email the same address within the cooldown', function () {
    Mail::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Invitation::create(['inviter_user_id' => $user->id, 'email' => 'friend@example.com', 'created_at' => now()->subHour()]);

    $this->postJson('/api/v1/invites', ['emails' => ['friend@example.com']])->assertStatus(202);

    Mail::assertNothingQueued();
});

it('normalizes + de-dupes the submitted list and validates addresses', function () {
    Mail::fake();
    Sanctum::actingAs(User::factory()->create());

    // Same address twice (mixed case) → one send.
    $this->postJson('/api/v1/invites', ['emails' => ['Dup@Example.com', 'dup@example.com']])->assertStatus(202);
    Mail::assertQueued(FriendInvite::class, 1);

    // Bad address → 422.
    $this->postJson('/api/v1/invites', ['emails' => ['not-an-email']])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    // Too many → 422.
    $this->postJson('/api/v1/invites', ['emails' => array_fill(0, 21, 'x@example.com')])
        ->assertStatus(422);
});

it('requires authentication', function () {
    $this->postJson('/api/v1/invites', ['emails' => ['a@example.com']])->assertUnauthorized();
});
