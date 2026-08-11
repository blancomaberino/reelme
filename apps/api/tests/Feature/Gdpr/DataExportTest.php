<?php

use App\Jobs\Gdpr\ExportUserData;
use App\Models\Device;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\PlatformAccount;
use App\Models\Share;
use App\Models\User;
use App\Notifications\DataExportReady;
use App\Services\Gdpr\AccountDeletion;
use App\Services\Gdpr\UserDataExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * POST /me/export (T-050, NFR-10 / GDPR Art. 20).
 *
 * Two ways this feature fails, and both are silent. It can under-deliver —
 * hand back an archive missing the half of someone's data that took effort to
 * collect — or it can over-deliver, and put a live access token or another
 * user's email inside a file we then mail a link to. The tests below are
 * roughly half about each.
 */
it('queues the build and answers 202 rather than blocking', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/me/export')
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(ExportUserData::class, fn ($job) => $job->userId === $user->id);
});

it('writes an archive holding a file per kind of record', function () {
    Storage::fake(config('media.disk'));
    $user = User::factory()->create(['country_code' => 'UY']);
    Share::factory()->for($user)->published()->create();

    $path = app(UserDataExporter::class)->export($user);

    expect(Storage::disk(config('media.disk'))->exists($path))->toBeTrue();

    // Open the real zip: asserting on the collector's return value would pass
    // for an archive that was never actually written.
    $local = tempnam(sys_get_temp_dir(), 'assert-export-');
    file_put_contents($local, Storage::disk(config('media.disk'))->get($path));

    $zip = new ZipArchive;
    expect($zip->open($local))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    expect($names)->toContain('profile.json', 'shares.json', 'ledger_entries.json', 'README.txt');

    $profile = json_decode((string) $zip->getFromName('profile.json'), true);
    // Art. 15/20 covers everything the user told us, including where they are.
    expect($profile['country_code'])->toBe('UY')
        ->and($profile['username'])->toBe($user->username)
        ->and(json_decode((string) $zip->getFromName('shares.json'), true))->toHaveCount(1);

    $zip->close();
    @unlink($local);
});

it('never puts a platform access token in the archive', function () {
    $user = User::factory()->create();
    PlatformAccount::factory()->for($user)->create(['access_token' => 'tok_super_secret_value']);

    $sections = app(UserDataExporter::class)->collect($user);
    $serialised = (string) json_encode($sections);

    // A token is a live credential for someone else's platform, not a fact
    // about the person — mailing one out in the name of transparency would be
    // handing over working access (NFR-9).
    expect($serialised)->not->toContain('tok_super_secret_value')
        ->and($sections['platform_accounts'][0])->not->toHaveKey('access_token')
        ->and($sections['platform_accounts'][0])->not->toHaveKey('refresh_token')
        // The metadata a user genuinely owns is still there.
        ->and($sections['platform_accounts'][0]['handle'])->not->toBeNull();
});

it('never puts a push token in the archive', function () {
    $user = User::factory()->create();
    Device::factory()->for($user)->create(['expo_push_token' => 'ExponentPushToken[secret]']);

    $serialised = (string) json_encode(app(UserDataExporter::class)->collect($user));

    expect($serialised)->not->toContain('ExponentPushToken[secret]');
});

it('gives handles for the people around them, and nothing more', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['username' => 'chef', 'email' => 'chef@private.example']);

    // The morph ALIAS, which is what production writes (Relation::enforceMorphMap).
    // Inserting User::class here made a broken exporter look correct — the
    // fixture and the buggy query agreed with each other and with nothing else,
    // so the follow graph came back empty on every real export.
    DB::table('follows')->insert([
        'follower_user_id' => $user->id,
        'followee_type' => $other->getMorphClass(),
        'followee_id' => $other->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sections = app(UserDataExporter::class)->collect($user);

    // A username is already visible to this user in the app. The address behind
    // it is not theirs to receive a copy of — an export that leaks it turns a
    // privacy feature into a disclosure.
    expect($sections['follows']['following'])->toBe(['chef'])
        ->and((string) json_encode($sections))->not->toContain('chef@private.example');
});

it('emails a signed link and files an in-app pointer without one', function () {
    Storage::fake(config('media.disk'));
    Notification::fake();
    $user = User::factory()->create();

    (new ExportUserData($user->id))->handle(app(UserDataExporter::class));

    Notification::assertSentTo($user, DataExportReady::class, function ($notification) use ($user) {
        $mail = $notification->toMail($user);
        $row = $notification->toDatabase($user);

        $url = (string) $mail->actionUrl;

        // A real, TIME-LIMITED URL — not the bare storage key. "Contains a
        // slash" would have been satisfied by `->action($label, $this->path)`,
        // which mails a link that does not work. The expiry marker differs by
        // driver (R2 presigns, the local disk signs a route, a faked disk
        // stamps an expiration), so match any of them rather than pinning the
        // test to one filesystem.
        return str_starts_with($url, 'http')
            && preg_match('/(signature|expiration|X-Amz-Signature)=/', $url) === 1
            && $url !== $row['export_path']
            // The link lives in the mail ONLY: a notification-center row
            // outlives the 24h signature by months, so one parked there would
            // be a stale handle on the densest personal file we produce.
            && $row['type'] === 'account.export_ready'
            && ! str_contains((string) json_encode($row), 'http');
    });
});

it('refuses to build an export for an account pending deletion', function () {
    Storage::fake(config('media.disk'));
    Notification::fake();
    $user = User::factory()->create();
    $user->delete();

    (new ExportUserData($user->id))->handle(app(UserDataExporter::class));

    // Handing someone a fresh dossier of the data we are about to erase is the
    // opposite of what the deletion request asked for.
    Notification::assertNothingSent();
    expect(Storage::disk(config('media.disk'))->allFiles('exports'))->toBeEmpty();
});

it('fills every section it claims to, not just the filenames', function () {
    $user = User::factory()->create();
    $place = Place::factory()->create(['name' => 'Bar Tinto']);
    $list = PlaceList::factory()->for($user)->create(['name' => 'Weekend']);

    DB::table('place_list_items')->insert(['place_list_id' => $list->id, 'place_id' => $place->id, 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('reviews')->insert(['place_id' => $place->id, 'user_id' => $user->id, 'rating' => 4, 'body' => 'lovely', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_place_tags')->insert(['user_id' => $user->id, 'place_id' => $place->id, 'label' => 'brunch', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'Test', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    $sections = app(UserDataExporter::class)->collect($user);

    // Asserting on filenames alone (they come from the collect() KEYS) let
    // every one of these collectors be replaced with `[]` and stay green — the
    // under-delivery half of this feature was entirely untested. `lists` in
    // particular runs a nested per-list subquery.
    expect($sections['lists'])->toHaveCount(1)
        ->and($sections['lists'][0]['places'])->toBe(['Bar Tinto'])
        ->and($sections['reviews'])->toHaveCount(1)
        ->and($sections['reviews'][0]['place'])->toBe('Bar Tinto')
        ->and($sections['place_tags'])->toHaveCount(1)
        ->and($sections['place_tags'][0]['label'])->toBe('brunch')
        ->and($sections['notifications'])->toHaveCount(1);
});

it('reports the deletion clock it is under', function () {
    config(['gdpr.purge_grace_days' => 14]);
    $user = User::factory()->create();
    app(AccountDeletion::class)->request($user);

    $sections = app(UserDataExporter::class)->collect(User::withTrashed()->find($user->id));

    // Someone exporting their data mid-deletion is asking precisely when it
    // goes. A null here would be the export quietly declining to say.
    expect($sections['profile']['deletion_requested_at'])->not->toBeNull()
        ->and($sections['profile']['deletion_scheduled_for'])->not->toBeNull();
});
