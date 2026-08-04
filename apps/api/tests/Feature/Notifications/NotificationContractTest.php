<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Contract tests (T-102): every notification payload must validate against
 * packages/contracts/schemas/notification.json — the file the mobile
 * `Notification` type is generated from.
 *
 * Without this the schema is decorative: the mobile `NotificationRow` is pinned
 * to the generated type, but nothing would check the API still answers in that
 * shape, and a renamed field would surface as a blank row on a phone rather
 * than a red test.
 */

/** Write one database notification directly, bypassing the queue. */
function contractNotification(User $user, array $data): void
{
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Fake',
        // The app registers a morph map ('user' => User::class), so the stored
        // discriminator is the alias, not the FQCN.
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => json_encode($data),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('validates every notification type against notification.json', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // One row per type the server emits, each with the params its type carries —
    // the shapes differ, which is exactly why all of them are checked.
    $payloads = [
        ['type' => 'share.published', 'url' => '/place/x', 'title' => 'a', 'body' => 'b', 'share_id' => 1, 'place_name' => 'Bar'],
        ['type' => 'share.review_needed', 'url' => '/shares/1/review', 'title' => 'a', 'body' => 'b', 'share_id' => 1],
        ['type' => 'share.failed', 'url' => '/shares/1/status', 'title' => 'a', 'body' => 'b', 'share_id' => 1],
        ['type' => 'social.follow', 'url' => '/users/ana', 'title' => 'a', 'body' => 'b', 'follower_username' => 'ana'],
        ['type' => 'influencer.claim_rejected', 'url' => '/influencer/1', 'title' => 'a', 'body' => 'b', 'influencer_handle' => 'chef', 'platform' => 'instagram'],
        ['type' => 'redemption.verified', 'url' => '/offers/1/redeem?redemptionId=2', 'title' => 'a', 'body' => 'b', 'redemption_id' => '2', 'place_name' => 'Bar'],
        ['type' => 'wallet.payout', 'url' => '/wallet', 'title' => 'a', 'body' => 'b', 'payout_id' => '1', 'amount_minor' => 4500, 'currency' => 'EUR'],
    ];

    foreach ($payloads as $payload) {
        contractNotification($user, $payload);
    }

    $rows = $this->getJson('/api/v1/notifications')->assertOk()->json('data');

    expect($rows)->toHaveCount(count($payloads));

    foreach ($rows as $row) {
        assertMatchesContract($row, 'notification');
    }
});

it('validates a legacy row that predates the stored copy', function () {
    // The rows that made the center render "share.published" as a title. They
    // are still in the table, so the contract has to admit them: `title`/`body`
    // are nullable precisely because of this shape.
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    contractNotification($user, ['type' => 'share.published', 'url' => '/place/x', 'share_id' => 29]);

    $row = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');

    assertMatchesContract($row, 'notification');
    expect($row['title'])->toBeNull()->and($row['body'])->toBeNull();
});
