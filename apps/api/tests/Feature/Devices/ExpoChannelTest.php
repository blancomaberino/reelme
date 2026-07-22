<?php

use App\Jobs\CheckExpoReceipts;
use App\Models\Device;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Notifications\SharePublished;
use App\Services\Push\ExpoPushClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/** A published share with a real place so the deep-link resolves. */
function publishedShareFor(User $user): Share
{
    $place = Place::factory()->create(['name' => 'La Cabrera']);
    $share = Share::factory()->for($user)->published()->create();
    $source = PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'source_post_id' => $share->source_post_id,
        'published_at' => now(),
    ]);
    // published_place_source_id is not fillable (status-machine field) — assign directly.
    $share->published_place_source_id = $source->id;
    $share->save();

    return $share->fresh();
}

it('sends one Expo message per device with the 05 §5.2 payload shape', function () {
    Bus::fake();
    $user = User::factory()->create();
    Device::factory()->for($user)->create(['expo_push_token' => 'tok-ok']);
    $share = publishedShareFor($user);

    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'r-1']]])]);

    app(ExpoChannel::class)->send($user, new SharePublished($share));

    Http::assertSent(function ($request) use ($share) {
        $body = $request->data();

        return str_contains($request->url(), '/push/send')
            && $body[0]['to'] === 'tok-ok'
            && $body[0]['sound'] === 'default'
            && $body[0]['channelId'] === 'default'
            && $body[0]['title'] === '¡Lugar añadido!'
            && $body[0]['data']['type'] === 'share.published'
            && $body[0]['data']['url'] === '/place/'.$share->publishedPlaceSource->place->slug;
    });
});

it('prunes a token Expo rejects with DeviceNotRegistered in the send ticket', function () {
    Bus::fake();
    $user = User::factory()->create();
    // Order matters: tickets align positionally with the token list (by id).
    Device::factory()->for($user)->create(['expo_push_token' => 'tok-ok']);
    Device::factory()->for($user)->create(['expo_push_token' => 'tok-dead']);
    $share = publishedShareFor($user);

    Http::fake(['exp.host/*' => Http::response(['data' => [
        ['status' => 'ok', 'id' => 'r-1'],
        ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
    ]])]);

    app(ExpoChannel::class)->send($user, new SharePublished($share));

    $this->assertDatabaseMissing('devices', ['expo_push_token' => 'tok-dead']);
    $this->assertDatabaseHas('devices', ['expo_push_token' => 'tok-ok']);
    // The accepted ticket is queued for a later receipt sweep.
    Bus::assertDispatched(CheckExpoReceipts::class, fn (CheckExpoReceipts $job) => true);
});

it('does not call Expo when the user has no registered devices', function () {
    Bus::fake();
    $user = User::factory()->create();
    $share = publishedShareFor($user);

    Http::fake();

    app(ExpoChannel::class)->send($user, new SharePublished($share));

    Http::assertNothingSent();
    Bus::assertNotDispatched(CheckExpoReceipts::class);
});

it('never throws when the Expo service is down (push is best-effort)', function () {
    Bus::fake();
    $user = User::factory()->create();
    Device::factory()->for($user)->create(['expo_push_token' => 'tok-ok']);
    $share = publishedShareFor($user);

    Http::fake(['exp.host/*' => Http::response('gateway down', 502)]);

    app(ExpoChannel::class)->send($user, new SharePublished($share));

    // Transport failure → no ticket ids to sweep, dead token untouched, no throw.
    Bus::assertNotDispatched(CheckExpoReceipts::class);
    $this->assertDatabaseHas('devices', ['expo_push_token' => 'tok-ok']);
});

it('prunes dead tokens found in the deferred delivery receipts', function () {
    Device::factory()->create(['expo_push_token' => 'tok-1']);
    Device::factory()->create(['expo_push_token' => 'tok-2']);

    Http::fake(['exp.host/*' => Http::response(['data' => [
        'r-1' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
        'r-2' => ['status' => 'ok'],
    ]])]);

    (new CheckExpoReceipts(['r-1' => 'tok-1', 'r-2' => 'tok-2']))->handle(app(ExpoPushClient::class));

    $this->assertDatabaseMissing('devices', ['expo_push_token' => 'tok-1']);
    $this->assertDatabaseHas('devices', ['expo_push_token' => 'tok-2']);
});
