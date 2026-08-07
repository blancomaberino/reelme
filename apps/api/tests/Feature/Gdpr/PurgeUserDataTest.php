<?php

use App\Enums\LedgerAccount;
use App\Enums\MediaKind;
use App\Enums\PayoutStatus;
use App\Enums\RedemptionStatus;
use App\Enums\ShareStatus;
use App\Models\Device;
use App\Models\Influencer;
use App\Models\MediaAsset;
use App\Models\Offer;
use App\Models\Payout;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\PlaceSource;
use App\Models\PlatformAccount;
use App\Models\Redemption;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Services\Gdpr\UserDataPurger;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The purge, table by table (T-050).
 *
 * A GDPR erasure is one of the few operations where "it ran without error" says
 * almost nothing — a purge that quietly skipped `platform_accounts` looks
 * exactly like one that worked. So every assertion below names a surface and
 * says what happened to it, and the three that would be catastrophic to get
 * wrong (live OAuth tokens, the financial record, other people's places) each
 * get their own test.
 */
/**
 * A user with something in every surface the purge touches.
 *
 * @return array{user: User, published: Share, draft: Share, place: Place}
 */
function purgeFixture(): array
{
    $user = User::factory()->create([
        'name' => 'Ana Real',
        'bio' => 'I like noodles',
        'is_influencer' => true,
        'stripe_connect_account_id' => 'acct_123',
    ]);

    PlatformAccount::factory()->for($user)->create();
    Device::factory()->for($user)->create();
    PlaceList::factory()->for($user)->create();
    $user->createToken('phone');

    $place = Place::factory()->create();

    // A published share: community data. Stays, anonymised.
    $publishedPost = SourcePost::factory()->create();
    $published = Share::factory()->for($user)->published()->create(['source_post_id' => $publishedPost->id]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $published->id,
        'source_post_id' => $publishedPost->id,
    ]);

    // A draft that never made it: purely theirs. Goes entirely.
    $draftPost = SourcePost::factory()->create();
    $draft = Share::factory()->for($user)->create([
        'status' => ShareStatus::Review,
        'source_post_id' => $draftPost->id,
    ]);
    MediaAsset::factory()->create(['source_post_id' => $draftPost->id, 'kind' => MediaKind::Video]);

    return ['user' => $user, 'published' => $published, 'draft' => $draft, 'place' => $place];
}

it('deletes every purely personal record', function () {
    ['user' => $user] = purgeFixture();

    app(UserDataPurger::class)->purge($user);

    // The OAuth tokens first — they are live credentials for someone else's
    // platform, and the one thing here that is dangerous rather than merely
    // private if it survives.
    expect(PlatformAccount::where('user_id', $user->id)->count())->toBe(0)
        ->and(Device::where('user_id', $user->id)->count())->toBe(0)
        ->and(PlaceList::where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->count())->toBe(0);
});

it('scrubs the surviving user row until it identifies nobody', function () {
    ['user' => $user] = purgeFixture();
    $originalEmail = $user->email;

    app(UserDataPurger::class)->purge($user);

    $row = DB::table('users')->find($user->id);

    expect($row)->not->toBeNull()
        ->and($row->name)->toBe(UserDataPurger::DELETED_NAME)
        ->and($row->bio)->toBeNull()
        ->and($row->email)->not->toBe($originalEmail)
        ->and($row->email)->toEndWith('@reelmap.invalid')
        ->and($row->username)->toStartWith('deleted_')
        ->and($row->is_influencer)->toBeFalse()
        ->and($row->stripe_connect_account_id)->toBeNull();
});

it('gives two purged accounts distinct identifiers', function () {
    // `email` and `username` are uniquely indexed. A shared literal like
    // "deleted@reelmap.invalid" would make the SECOND deletion fail — the
    // failure mode being an account that cannot be erased at all.
    $first = User::factory()->create();
    $second = User::factory()->create();

    app(UserDataPurger::class)->purge($first);
    app(UserDataPurger::class)->purge($second);

    expect(DB::table('users')->find($first->id)->email)
        ->not->toBe(DB::table('users')->find($second->id)->email);
});

it('keeps published shares and the places they put on the map', function () {
    ['user' => $user, 'published' => $published, 'draft' => $draft, 'place' => $place] = purgeFixture();

    app(UserDataPurger::class)->purge($user);

    // The community keeps what the community was given. Deleting this would
    // silently remove a live place from other people's maps — an erasure that
    // takes other users' data with it.
    expect(Share::find($published->id))->not->toBeNull()
        ->and(Place::find($place->id))->not->toBeNull()
        ->and(PlaceSource::where('share_id', $published->id)->exists())->toBeTrue()
        // The draft was only ever theirs.
        ->and(Share::find($draft->id))->toBeNull();
});

it('deletes the media behind a purged draft, and its stored object', function () {
    Storage::fake(config('media.disk'));
    ['user' => $user, 'draft' => $draft] = purgeFixture();

    $asset = MediaAsset::where('source_post_id', $draft->source_post_id)->firstOrFail();
    Storage::disk(config('media.disk'))->put($asset->storage_path, 'video-bytes');

    app(UserDataPurger::class)->purge($user);

    expect(MediaAsset::find($asset->id))->toBeNull()
        // The row going without the object is a leak nobody has a handle on:
        // no row means no future pass can ever find the file again.
        ->and(Storage::disk(config('media.disk'))->exists($asset->storage_path))->toBeFalse();
});

it('leaves a source post alone when somebody else still shares it', function () {
    ['user' => $user, 'draft' => $draft] = purgeFixture();

    // The same reel, shared by two people. source_posts is keyed on
    // (platform, external_id), so it is ONE row serving both.
    $other = User::factory()->create();
    Share::factory()->for($other)->published()->create(['source_post_id' => $draft->source_post_id]);

    app(UserDataPurger::class)->purge($user);

    expect(SourcePost::find($draft->source_post_id))->not->toBeNull()
        ->and(MediaAsset::where('source_post_id', $draft->source_post_id)->exists())->toBeTrue();
});

it('leaves the financial record intact and still balanced', function () {
    ['user' => $user] = purgeFixture();

    app(LedgerService::class)->record('test:earn:'.$user->id, [
        LedgerLine::debit(LedgerAccount::RestaurantReceivable, 500, 'EUR'),
        LedgerLine::credit(LedgerAccount::InfluencerEarnings, 500, 'EUR', userId: $user->id),
    ]);

    $offer = Offer::factory()->create();
    $redemption = Redemption::factory()->create([
        'user_id' => $user->id,
        'offer_id' => $offer->id,
        'status' => RedemptionStatus::Redeemed,
        'redeemed_at' => now(),
    ]);

    app(UserDataPurger::class)->purge($user);

    // Retained because we are legally required to (ADR-009) and because the
    // rows anchor on users.id — dropping the anchor would not anonymise the
    // books, it would corrupt them.
    expect(DB::table('ledger_entries')->where('user_id', $user->id)->count())->toBe(1)
        ->and(Redemption::find($redemption->id))->not->toBeNull();

    $sums = DB::table('ledger_entries')
        ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END) AS debits")
        ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END) AS credits")
        ->first();

    // Postgres returns SUM() as a string through PDO; toBe is identical.
    expect((int) $sums->debits)->toBe((int) $sums->credits);
});

it('holds the Stripe linkage while a payout is still in flight', function () {
    ['user' => $user] = purgeFixture();
    Payout::factory()->create([
        'user_id' => $user->id,
        'status' => PayoutStatus::Processing,
    ]);

    app(UserDataPurger::class)->purge($user);

    $row = DB::table('users')->find($user->id);

    // Money already moving has to land somewhere. Everything else is gone;
    // this one field waits for the transfer to settle (PurgeUserData
    // re-dispatches to finish it).
    expect($row->stripe_connect_account_id)->toBe('acct_123')
        ->and($row->name)->toBe(UserDataPurger::DELETED_NAME);
});

it('unclaims the influencer identity without deleting it', function () {
    ['user' => $user] = purgeFixture();
    $influencer = Influencer::factory()->create(['claimed_by_user_id' => $user->id]);

    app(UserDataPurger::class)->purge($user);

    $influencer->refresh();

    // An influencer row is public business data about a creator account
    // (R-06 lawful basis), not the private data of whoever claimed it — so the
    // LINK goes and the identity stays, with its posts and its attribution.
    expect($influencer->exists)->toBeTrue()
        ->and($influencer->claimed_by_user_id)->toBeNull();
});

it('drops the follow graph in both directions', function () {
    ['user' => $user] = purgeFixture();
    $other = User::factory()->create();

    DB::table('follows')->insert([
        ['follower_user_id' => $user->id, 'followee_type' => User::class, 'followee_id' => $other->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_user_id' => $other->id, 'followee_type' => User::class, 'followee_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app(UserDataPurger::class)->purge($user);

    // The second row is somebody else's edge, but it names this user — and a
    // follower list is precisely the "who do you know" graph erasure covers.
    expect(DB::table('follows')->where('follower_user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('follows')->where('followee_id', $user->id)
            ->where('followee_type', User::class)->count())->toBe(0);
});

it('is idempotent', function () {
    ['user' => $user] = purgeFixture();

    app(UserDataPurger::class)->purge($user);
    $after = DB::table('users')->find($user->id);

    // Re-running must finish the job, never double anything or throw on rows
    // that are already gone. This is what makes a manual re-dispatch safe after
    // a partial failure.
    app(UserDataPurger::class)->purge(User::withTrashed()->find($user->id));

    expect(DB::table('users')->find($user->id)->email)->toBe($after->email);
});
