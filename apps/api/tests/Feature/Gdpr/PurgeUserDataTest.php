<?php

use App\Enums\LedgerAccount;
use App\Enums\MediaKind;
use App\Enums\PayoutStatus;
use App\Enums\RedemptionStatus;
use App\Enums\ReportStatus;
use App\Enums\ShareStatus;
use App\Enums\SuggestionStatus;
use App\Models\Device;
use App\Models\Influencer;
use App\Models\MediaAsset;
use App\Models\Offer;
use App\Models\Payout;
use App\Models\Place;
use App\Models\PlaceEditSuggestion;
use App\Models\PlaceList;
use App\Models\PlaceSource;
use App\Models\PlatformAccount;
use App\Models\Redemption;
use App\Models\Report;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Services\Gdpr\UserDataPurger;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
function gdprPurgeFixture(): array
{
    $user = User::factory()->create([
        'name' => 'Ana Real',
        'bio' => 'I like noodles',
        'country_code' => 'UY',
        // Set explicitly so the scrub assertion cannot pass on a column that
        // was already null — the factory does not populate it (T-113).
        'age_verified_at' => now(),
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

beforeEach(function () {
    // Every purge deletes storage objects (media, avatar, export archives). Without
    // a file-level fake, the tests that do not opt in run against the REAL
    // local_media disk — harmless only by luck, since Flysystem's delete on a
    // missing path is a no-op.
    Storage::fake(config('media.disk'));
});

it('deletes every purely personal record', function () {
    ['user' => $user] = gdprPurgeFixture();

    app(UserDataPurger::class)->purge($user);

    // The OAuth tokens first — they are live credentials for someone else's
    // platform, and the one thing here that is dangerous rather than merely
    // private if it survives.
    expect(PlatformAccount::where('user_id', $user->id)->count())->toBe(0)
        ->and(Device::where('user_id', $user->id)->count())->toBe(0)
        ->and(PlaceList::where('user_id', $user->id)->count())->toBe(0)
        // getMorphClass(), NOT User::class. `Relation::enforceMorphMap` stores
        // the alias `user` on disk, so asserting on the FQCN counts zero rows
        // whether or not the purge did anything — which is precisely how the
        // purge shipped for a while silently deleting no tokens at all.
        ->and(DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->count())->toBe(0);
});

it('scrubs the surviving user row until it identifies nobody', function () {
    ['user' => $user] = gdprPurgeFixture();
    $originalEmail = $user->email;

    app(UserDataPurger::class)->purge($user);

    $row = DB::table('users')->find($user->id);

    expect($row)->not->toBeNull()
        ->and($row->name)->toBe(UserDataPurger::DELETED_NAME)
        ->and($row->bio)->toBeNull()
        // Self-declared and publicly displayed, so erasure has to reach it too.
        ->and($row->country_code)->toBeNull()
        ->and($row->age_verified_at)->toBeNull()
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
    ['user' => $user, 'published' => $published, 'draft' => $draft, 'place' => $place] = gdprPurgeFixture();

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
    ['user' => $user, 'draft' => $draft] = gdprPurgeFixture();

    $asset = MediaAsset::where('source_post_id', $draft->source_post_id)->firstOrFail();
    Storage::disk(config('media.disk'))->put($asset->storage_path, 'video-bytes');

    app(UserDataPurger::class)->purge($user);

    expect(MediaAsset::find($asset->id))->toBeNull()
        // The row going without the object is a leak nobody has a handle on:
        // no row means no future pass can ever find the file again.
        ->and(Storage::disk(config('media.disk'))->exists($asset->storage_path))->toBeFalse();
});

it('leaves a source post alone when somebody else still shares it', function () {
    ['user' => $user, 'draft' => $draft] = gdprPurgeFixture();

    // The same reel, shared by two people. source_posts is keyed on
    // (platform, external_id), so it is ONE row serving both.
    $other = User::factory()->create();
    Share::factory()->for($other)->published()->create(['source_post_id' => $draft->source_post_id]);

    app(UserDataPurger::class)->purge($user);

    expect(SourcePost::find($draft->source_post_id))->not->toBeNull()
        ->and(MediaAsset::where('source_post_id', $draft->source_post_id)->exists())->toBeTrue();
});

it('leaves the financial record intact and still balanced', function () {
    ['user' => $user] = gdprPurgeFixture();

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
    ['user' => $user] = gdprPurgeFixture();
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
    ['user' => $user] = gdprPurgeFixture();
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
    ['user' => $user] = gdprPurgeFixture();
    $other = User::factory()->create();

    // The morph ALIAS, which is what production writes. Inserting User::class
    // here is what made the broken purge look correct: the fixture and the
    // buggy query agreed with each other and with nothing else.
    $morph = $user->getMorphClass();

    DB::table('follows')->insert([
        ['follower_user_id' => $user->id, 'followee_type' => $morph, 'followee_id' => $other->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_user_id' => $other->id, 'followee_type' => $morph, 'followee_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app(UserDataPurger::class)->purge($user);

    // The second row is somebody else's edge, but it names this user — and a
    // follower list is precisely the "who do you know" graph erasure covers.
    expect(DB::table('follows')->where('follower_user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('follows')->where('followee_id', $user->id)
            ->where('followee_type', $morph)->count())->toBe(0);
});

it('is idempotent', function () {
    ['user' => $user] = gdprPurgeFixture();

    app(UserDataPurger::class)->purge($user);
    $after = DB::table('users')->find($user->id);

    // Re-running must finish the job, never double anything or throw on rows
    // that are already gone. This is what makes a manual re-dispatch safe after
    // a partial failure.
    app(UserDataPurger::class)->purge(User::withTrashed()->find($user->id));

    expect(DB::table('users')->find($user->id)->email)->toBe($after->email);
});

it('leaves nothing behind in any personal table', function () {
    ['user' => $user] = gdprPurgeFixture();
    $morph = $user->getMorphClass();
    $place = Place::factory()->create();

    // One row per surface `deletePersonalRows()` claims to clear. Without this,
    // deleting any single line from that method left the whole suite green — a
    // GDPR erasure that skips `reviews` or `notifications` looks identical to
    // one that worked.
    DB::table('sessions')->insert(['id' => 'sess_1', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => 'x', 'last_activity' => time()]);
    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'Test', 'notifiable_type' => $morph, 'notifiable_id' => $user->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('reviews')->insert(['place_id' => $place->id, 'user_id' => $user->id, 'rating' => 5, 'body' => 'my words', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_place_tags')->insert(['user_id' => $user->id, 'place_id' => $place->id, 'label' => 'brunch', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hidden_places')->insert(['user_id' => $user->id, 'place_id' => $place->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('invitations')->insert(['inviter_user_id' => $user->id, 'email' => 'friend@test.dev', 'created_at' => now()]);

    app(UserDataPurger::class)->purge($user);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_type', $morph)->where('notifiable_id', $user->id)->count())->toBe(0)
        ->and(DB::table('reviews')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('user_place_tags')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('hidden_places')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('invitations')->where('inviter_user_id', $user->id)->count())->toBe(0);
});

it('takes the moderation flags they filed AND the ones naming them', function () {
    ['user' => $user] = gdprPurgeFixture();
    $other = User::factory()->create();
    $share = Share::factory()->create();

    Report::factory()->against($share)->create(['reporter_user_id' => $user->id]);
    $aboutThem = Report::factory()->against($user)->create(['reporter_user_id' => $other->id]);

    app(UserDataPurger::class)->purge($user);

    // Both directions. `details` is 2000 characters of free prose — where PII
    // actually lands — and a report naming this person keeps identifying them
    // after the erasure. The FK cascade never fires, because the row is
    // anonymised rather than deleted.
    expect(DB::table('reports')->where('reporter_user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('reports')->where('id', $aboutThem->id)->count())->toBe(0);
});

it('stops a purged admin being the recorded decider on retained moderation rows', function () {
    ['user' => $admin] = gdprPurgeFixture();
    $share = Share::factory()->create();
    $report = Report::factory()->against($share)->resolved()->create(['resolved_by_user_id' => $admin->id]);

    app(UserDataPurger::class)->purge($admin);

    // Same rule as the rest of anonymiseRetainedRows: the DECISION is the
    // record we keep, the decider is not. Without this a purged admin stays
    // linked to every report they ever closed.
    expect($report->fresh()->resolved_by_user_id)->toBeNull()
        ->and($report->fresh()->status)->toBe(ReportStatus::Resolved);
});

it('deletes an invitation addressed TO the purged user', function () {
    ['user' => $user] = gdprPurgeFixture();
    $other = User::factory()->create();
    DB::table('invitations')->insert(['inviter_user_id' => $other->id, 'email' => $user->email, 'created_at' => now()]);

    app(UserDataPurger::class)->purge($user);

    // Somebody else's row, but `invitations.email` holds this person's raw
    // address — which is about to stop existing everywhere else.
    expect(DB::table('invitations')->where('email', $user->email)->count())->toBe(0);
});

it('cuts every retained pointer at the purged user', function () {
    ['user' => $user] = gdprPurgeFixture();
    $place = Place::factory()->create();
    $offer = Offer::factory()->create();

    DB::table('place_edits')->insert(['place_id' => $place->id, 'user_id' => $user->id, 'origin' => 'manual', 'changes' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $redemption = Redemption::factory()->create(['offer_id' => $offer->id, 'redeemed_by_user_id' => $user->id, 'status' => RedemptionStatus::Redeemed, 'redeemed_at' => now()]);

    app(UserDataPurger::class)->purge($user);

    // These rows STAY — they are somebody else's record — but the pointer at
    // this person has to be cut, not left aimed at a scrubbed row. The whole
    // method could previously be made a no-op with nothing failing.
    expect(DB::table('place_edits')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('place_edits')->whereNull('user_id')->count())->toBe(1)
        ->and(Redemption::find($redemption->id)->redeemed_by_user_id)->toBeNull();
});

it('cuts both pointers on a suggested edit — the submitter and the reviewer', function () {
    ['user' => $user] = gdprPurgeFixture();

    // The two ends of one row, and both name a person: a pending proposal this
    // user filed, and a settled one this user (as an admin) decided.
    $mine = PlaceEditSuggestion::factory()->create(['user_id' => $user->id]);
    $reviewed = PlaceEditSuggestion::factory()->approved()->create(['reviewed_by_user_id' => $user->id]);

    app(UserDataPurger::class)->purge($user);

    // The rows STAY — a correction is the venue's to act on, and a moderation
    // decision is the record we keep — but neither may still name this person.
    expect($mine->fresh()->user_id)->toBeNull()
        ->and($mine->fresh()->status)->toBe(SuggestionStatus::Pending)
        ->and($reviewed->fresh()->reviewed_by_user_id)->toBeNull();
});

/**
 * The free-text note (T-112) is the one part of this table that anonymising is
 * not enough for.
 *
 * T-083's rule — keep the row, null `user_id` — rests on a field patch being
 * the BUSINESS's record rather than the submitter's. A note is 2000 characters
 * of somebody's own words, which is exactly where PII lands, and it is why
 * `reports` are deleted outright rather than anonymised. So the split is on
 * what the row would be worth without the prose.
 */
it('deletes a note-only suggestion and strips the note from the ones it keeps', function () {
    ['user' => $user] = gdprPurgeFixture();

    // Nothing in it but the person's words: no patch, nothing a venue could act
    // on once they are gone.
    $noteOnly = PlaceEditSuggestion::factory()->noteOnly('My sister worked here until March.')
        ->create(['user_id' => $user->id]);
    // A patch AND a note — the patch is the venue's record and survives.
    $mixed = PlaceEditSuggestion::factory()->create([
        'user_id' => $user->id,
        'changes' => ['phone' => ['from' => null, 'to' => '+598 2 900 0000']],
        'note' => 'Also, I go there every Tuesday with my kids.',
    ]);
    // Already applied. "We acted on it" is not a lawful basis for keeping the
    // prose either — the diff and the audit row are what survive.
    $approved = PlaceEditSuggestion::factory()->approved()->create([
        'user_id' => $user->id,
        'changes' => ['city' => ['from' => 'Montevideo', 'to' => 'Canelones']],
        'note' => 'I live around the corner from it.',
    ]);
    // Somebody else's row, in the same states — the purge must not reach it.
    $stranger = PlaceEditSuggestion::factory()->noteOnly('Not this user\'s note.')->create();

    app(UserDataPurger::class)->purge($user);

    expect(PlaceEditSuggestion::query()->find($noteOnly->id))->toBeNull()
        ->and($mixed->fresh()->note)->toBeNull()
        // The correction itself is untouched: a venue still needs its phone fixed.
        ->and($mixed->fresh()->patch())->toBe(['phone' => '+598 2 900 0000'])
        ->and($mixed->fresh()->user_id)->toBeNull()
        ->and($approved->fresh()->note)->toBeNull()
        ->and($approved->fresh()->status)->toBe(SuggestionStatus::Approved)
        ->and($stranger->fresh()->note)->toBe('Not this user\'s note.');
});

/**
 * Idempotence, which every step of this purge is built for: the deferred Stripe
 * pass and any manual retry run the whole thing again.
 */
it('purges suggestion notes the same way on a second run', function () {
    ['user' => $user] = gdprPurgeFixture();
    PlaceEditSuggestion::factory()->noteOnly()->create(['user_id' => $user->id]);
    $mixed = PlaceEditSuggestion::factory()->create(['user_id' => $user->id, 'note' => 'something']);

    app(UserDataPurger::class)->purge($user);
    app(UserDataPurger::class)->purge($user->fresh());

    expect(PlaceEditSuggestion::query()->count())->toBe(1)
        ->and($mixed->fresh()->note)->toBeNull();
});

it('leaves the follow counters of everyone else correct', function () {
    ['user' => $user] = gdprPurgeFixture();
    $followed = User::factory()->create(['followers_count' => 1]);
    $follower = User::factory()->create(['following_count' => 1]);
    $morph = $user->getMorphClass();

    DB::table('follows')->insert([
        ['follower_user_id' => $user->id, 'followee_type' => $morph, 'followee_id' => $followed->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_user_id' => $follower->id, 'followee_type' => $morph, 'followee_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    app(UserDataPurger::class)->purge($user);

    // These counters are denormalised and hand-maintained, so a bulk delete of
    // the edges beneath them leaves permanent drift on OTHER people's profiles
    // — visible, wrong, and with nothing that ever recomputes it.
    expect($followed->fresh()->followers_count)->toBe(0)
        ->and($follower->fresh()->following_count)->toBe(0);
});

it('deletes the user avatar, and never a remote one', function () {
    ['user' => $user] = gdprPurgeFixture();
    $disk = Storage::disk(config('media.disk'));
    $disk->put('avatars/mine.jpg', 'bytes');
    $user->forceFill(['avatar_path' => 'avatars/mine.jpg'])->save();

    app(UserDataPurger::class)->purge($user);

    expect($disk->exists('avatars/mine.jpg'))->toBeFalse();

    // A provider's CDN URL is not ours to delete, and passing it to delete()
    // would be nonsense rather than a no-op.
    $remote = User::factory()->create(['avatar_path' => 'https://cdn.example/pic.jpg']);
    app(UserDataPurger::class)->purge($remote);
    expect(DB::table('users')->find($remote->id)->avatar_path)->toBeNull();
});

it('takes the user own export archives with it', function () {
    ['user' => $user] = gdprPurgeFixture();
    $disk = Storage::disk(config('media.disk'));
    $disk->put("exports/{$user->id}/dossier.zip", 'archive');
    $disk->put('exports/999/someone-else.zip', 'archive');

    app(UserDataPurger::class)->purge($user);

    // An export is the densest single file of this person's data we produce.
    // The retention sweep would otherwise leave it on disk for a week AFTER the
    // irreversible erasure completed.
    expect($disk->exists("exports/{$user->id}/dossier.zip"))->toBeFalse()
        ->and($disk->exists('exports/999/someone-else.zip'))->toBeTrue();
});

it('completes the purge even when the storage delete throws', function () {
    ['user' => $user, 'draft' => $draft] = gdprPurgeFixture();

    // A bucket outage must not stop a legally-required erasure. The row goes
    // either way; the object is swept by the retention pass, which exists for
    // exactly this.
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('bucket down'));

    app(UserDataPurger::class)->purge($user);

    expect(DB::table('users')->find($user->id)->email)->toEndWith('@reelmap.invalid')
        ->and(Share::find($draft->id))->toBeNull();
});

it('releases the Stripe linkage on a later pass once the payout settles', function () {
    ['user' => $user] = gdprPurgeFixture();
    $payout = Payout::factory()->create(['user_id' => $user->id, 'status' => PayoutStatus::Processing]);

    app(UserDataPurger::class)->purge($user);
    expect(DB::table('users')->find($user->id)->stripe_connect_account_id)->toBe('acct_123');

    // The deferral is only defensible if something actually comes back for it.
    // Without this the linkage could be retained forever and nothing would say so.
    $payout->forceFill(['status' => PayoutStatus::Paid])->save();
    app(UserDataPurger::class)->purge(User::withTrashed()->find($user->id));

    expect(DB::table('users')->find($user->id)->stripe_connect_account_id)->toBeNull();
});
