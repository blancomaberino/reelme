<?php

use App\Jobs\Gdpr\ExportUserData;
use App\Models\Device;
use App\Models\PlatformAccount;
use App\Models\Share;
use App\Models\User;
use App\Notifications\DataExportReady;
use App\Services\Gdpr\UserDataExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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
    $user = User::factory()->create();
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
    expect($profile['username'])->toBe($user->username)
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

    DB::table('follows')->insert([
        'follower_user_id' => $user->id,
        'followee_type' => User::class,
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

        // The link lives in the mail only. A notification-center row outlives
        // the 24h signature by months, so a link parked there would be a stale
        // handle on the densest personal file we produce.
        return str_contains((string) $mail->actionUrl, '/')
            && $row['type'] === 'account.export_ready'
            && ! str_contains((string) json_encode($row), 'signature');
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
