<?php

use App\Models\Influencer;
use App\Models\Share;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Notifications\InfluencerClaimRejected;
use App\Notifications\NewFollower;
use App\Notifications\SharePublished;
use App\Notifications\ShareReviewNeeded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The notification center (T-040, 03 §2.15).
 *
 * A push is ephemeral — dismissed, missed offline, or delivered to a device the
 * user has since replaced. The database twin is the durable record, so what
 * matters here is that it is complete enough to RENDER (the center reads the
 * stored row, not the push) and that it is impossible to reach another
 * account's rows.
 */

/** Write one database notification directly, bypassing the queue. */
function notify(User $user, array $data, ?string $readAt = null): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'App\\Notifications\\Fake',
        // The app registers a MORPH MAP ('user' => User::class), so the stored
        // discriminator is the alias, not the FQCN. Hard-coding User::class
        // here inserts rows the relation can never see.
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => json_encode($data),
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function payload(string $type = 'share.published'): array
{
    return ['type' => $type, 'url' => '/place/bar-tinta', 'title' => 'Published', 'body' => 'Bar Tinta is on your map.'];
}

describe('GET /notifications', function () {
    it('lists only the caller’s own notifications, newest first', function () {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $older = notify($me, payload());
        $this->travel(1)->minutes();
        $newer = notify($me, payload('social.follow'));
        notify($other, payload());

        $body = $this->actingAs($me)->getJson('/api/v1/notifications')->assertOk()->json();

        expect(array_column($body['data'], 'id'))->toBe([$newer, $older]);
    });

    it('serializes the machine type and the copy the center renders', function () {
        $me = User::factory()->create();
        notify($me, payload());

        $row = $this->actingAs($me)->getJson('/api/v1/notifications')->assertOk()->json('data.0');

        // `type` is data.type, NOT the PHP class — the class is an
        // implementation detail a rename would change.
        expect($row['type'])->toBe('share.published')
            ->and($row['title'])->toBe('Published')
            ->and($row['body'])->toBe('Bar Tinta is on your map.')
            ->and($row['url'])->toBe('/place/bar-tinta')
            ->and($row['read_at'])->toBeNull();
    });

    it('reports the unread count on every page, not just the first', function () {
        // The app badges from whatever response it last saw; a count only on
        // page one would show a number the user has already cleared.
        $me = User::factory()->create();
        foreach (range(1, 4) as $i) {
            notify($me, payload());
            $this->travel(1)->minutes();
        }

        $first = $this->actingAs($me)->getJson('/api/v1/notifications?limit=2')->assertOk()->json();
        expect($first['meta']['unread_count'])->toBe(4)
            ->and($first['data'])->toHaveCount(2);

        $second = $this->actingAs($me)
            ->getJson('/api/v1/notifications?limit=2&cursor='.urlencode($first['meta']['pagination']['next_cursor']))
            ->assertOk()->json();

        expect($second['meta']['unread_count'])->toBe(4)
            ->and($second['data'])->toHaveCount(2);
    });

    it('paginates without repeating or dropping a row', function () {
        $me = User::factory()->create();
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = notify($me, payload());
            $this->travel(1)->minutes();
        }

        $seen = [];
        $cursor = null;
        for ($i = 0; $i < 10; $i++) {
            $url = '/api/v1/notifications?limit=2'.($cursor ? '&cursor='.urlencode($cursor) : '');
            $body = $this->actingAs($me)->getJson($url)->assertOk()->json();
            $seen = array_merge($seen, array_column($body['data'], 'id'));
            $cursor = $body['meta']['pagination']['next_cursor'];
            if ($cursor === null) {
                break;
            }
        }

        expect($cursor)->toBeNull()
            ->and(array_unique($seen))->toHaveCount(5)
            ->and(collect($seen)->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
    });

    it('filters to unread with ?unread=1', function () {
        $me = User::factory()->create();
        notify($me, payload(), readAt: now()->toDateTimeString());
        $unread = notify($me, payload());

        $body = $this->actingAs($me)->getJson('/api/v1/notifications?unread=1')->assertOk()->json();

        expect($body['data'])->toHaveCount(1)
            ->and($body['data'][0]['id'])->toBe($unread)
            ->and($body['meta']['unread_count'])->toBe(1);
    });

    it('requires authentication', function () {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    });
});

describe('POST /notifications/read', function () {
    it('marks the given ids read and returns the new count', function () {
        $me = User::factory()->create();
        $a = notify($me, payload());
        $b = notify($me, payload());

        $body = $this->actingAs($me)->postJson('/api/v1/notifications/read', ['ids' => [$a]])
            ->assertOk()->json();

        expect($body['data']['unread_count'])->toBe(1);
        expect(DB::table('notifications')->where('id', $a)->value('read_at'))->not->toBeNull();
        expect(DB::table('notifications')->where('id', $b)->value('read_at'))->toBeNull();
    });

    it('marks everything with {all: true}', function () {
        $me = User::factory()->create();
        notify($me, payload());
        notify($me, payload());

        $body = $this->actingAs($me)->postJson('/api/v1/notifications/read', ['all' => true])
            ->assertOk()->json();

        expect($body['data']['unread_count'])->toBe(0);
    });

    /**
     * The security property. Ids are UUIDs, so guessing is impractical — but a
     * 403 on a foreign id would still confirm the id EXISTS, turning this into
     * an enumeration oracle. It is silently ignored instead.
     */
    it('silently ignores another user’s id rather than leaking its existence', function () {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $theirs = notify($other, payload());

        $body = $this->actingAs($me)->postJson('/api/v1/notifications/read', ['ids' => [$theirs]])
            ->assertOk()->json();

        expect($body['data']['unread_count'])->toBe(0); // I had none to begin with
        expect(DB::table('notifications')->where('id', $theirs)->value('read_at'))->toBeNull();
    });

    it('never clears another user’s notifications with {all: true}', function () {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $theirs = notify($other, payload());

        $this->actingAs($me)->postJson('/api/v1/notifications/read', ['all' => true])->assertOk();

        expect(DB::table('notifications')->where('id', $theirs)->value('read_at'))->toBeNull();
    });

    it('rejects a request that names neither ids nor all', function () {
        // Succeeding silently would let the app clear a badge it never cleared.
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/notifications/read', [])
            ->assertStatus(422);
    });

    it('rejects a non-uuid id', function () {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/notifications/read', ['ids' => ['not-a-uuid']])
            ->assertStatus(422);
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/notifications/read', ['all' => true])->assertUnauthorized();
    });
});

/**
 * Table-driven: every M3 notification type must persist a row the center can
 * render — `type` for the switch, `url` for the tap, `title`/`body` for the
 * text. A class that writes only routing data lists as a blank line.
 */
it('persists a renderable database row for every M3 notification type', function () {
    // No faking needed: `toDatabase()` is a pure payload builder, so this
    // asserts the CONTRACT of each class without dispatching anything.
    $owner = User::factory()->create();
    $share = Share::factory()->for($owner)->create();

    $cases = [
        'share.published' => new SharePublished($share),
        'share.review_needed' => new ShareReviewNeeded($share),
        'social.follow' => new NewFollower(User::factory()->create()),
        'influencer.claim_rejected' => new InfluencerClaimRejected(Influencer::factory()->create()),
    ];

    foreach ($cases as $expectedType => $notification) {
        $payload = $notification->toDatabase($owner);

        expect($payload)->toHaveKeys(['type', 'url', 'title', 'body'])
            ->and($payload['type'])->toBe($expectedType)
            ->and($payload['url'])->toBeString()->not->toBe('')
            ->and($payload['title'])->toBeString()->not->toBe('')
            ->and($payload['body'])->toBeString()->not->toBe('');
    }
});

it('sends the follow notification on both channels now that devices exist', function () {
    // T-037 shipped it database-only because T-027 had not landed. It had.
    $via = (new NewFollower(User::factory()->create()))->via(User::factory()->create());

    expect($via)->toContain('database')
        ->and($via)->toContain(ExpoChannel::class);
});
