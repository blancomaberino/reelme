<?php

use App\Enums\PlaceStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ShareStatus;
use App\Models\Offer;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Report;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Services\Moderation\ReportActions;

/**
 * Acting on a report (T-049).
 *
 * The thing worth pinning is that these route to the EXISTING take-downs
 * (T-072's ShareModerator/PlaceModerator, T-008's ban) rather than
 * re-implementing them. A second way to hide a place would diverge from the
 * first, and the divergence would only ever be discovered by a user seeing
 * something that was supposed to be gone.
 */
function reportAgainst(mixed $target, ReportReason $reason = ReportReason::Inappropriate): Report
{
    return Report::factory()->against($target)->reason($reason)->create();
}

it('takes a reported share down and closes the report', function () {
    $admin = User::factory()->admin()->create();
    $place = Place::factory()->create(['status' => PlaceStatus::Active]);
    $post = SourcePost::factory()->create();
    $share = Share::factory()->published()->create(['source_post_id' => $post->id]);
    $source = PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'source_post_id' => $post->id,
        'published_at' => now(),
    ]);
    $report = reportAgainst($share);

    expect(app(ReportActions::class)->takeDown($report, $admin, 'Not food.'))->toBeTrue();

    $share->refresh();
    $report->refresh();

    // Routed through ShareModerator: the share is unpublished and its source
    // loses `published_at`, which is what actually drops the pin.
    expect($share->status)->toBe(ShareStatus::Rejected)
        ->and($source->fresh()->published_at)->toBeNull()
        ->and($report->status)->toBe(ReportStatus::Resolved)
        ->and($report->resolved_by_user_id)->toBe($admin->id)
        ->and($report->resolved_at)->not->toBeNull();
});

it('hides a reported place using the same status the admin UI uses', function () {
    $admin = User::factory()->admin()->create();
    $place = Place::factory()->create(['status' => PlaceStatus::Active]);
    $report = reportAgainst($place);

    app(ReportActions::class)->takeDown($report, $admin, 'Not a restaurant.');

    // PlaceStatus::Hidden, NOT a new `hidden_at` column: T-072 and ADR-085
    // already made status the take-down mechanism, and a second one would mean
    // two places to check before believing something is off the map.
    expect($place->fresh()->status)->toBe(PlaceStatus::Hidden)
        ->and($report->fresh()->status)->toBe(ReportStatus::Resolved);
});

it('bans a reported user without touching their financial history', function () {
    $admin = User::factory()->admin()->create();
    $offender = User::factory()->create();
    $offender->createToken('phone');
    $report = reportAgainst($offender, ReportReason::Fraud);

    expect(app(ReportActions::class)->banReported($report, $admin, 'Impersonation.'))->toBeTrue();

    $offender = User::withTrashed()->find($offender->id);

    expect($offender->trashed())->toBeTrue()
        ->and($offender->tokens()->count())->toBe(0)
        // A ban is NOT a deletion request. Setting this would make the account
        // purgeable and — worse — signable-back-in during the grace period,
        // which is exactly the collision ADR-050 exists to prevent.
        ->and($offender->deletion_requested_at)->toBeNull()
        ->and($report->fresh()->status)->toBe(ReportStatus::Resolved);
});

it('refuses to let an admin ban themselves through a report', function () {
    $admin = User::factory()->admin()->create();
    $report = reportAgainst($admin);

    expect(app(ReportActions::class)->banReported($report, $admin, 'oops'))->toBeFalse();
    expect($admin->fresh()->trashed())->toBeFalse();
});

it('reports honestly when there is nothing to take down', function () {
    $admin = User::factory()->admin()->create();
    $post = SourcePost::factory()->create();
    $report = reportAgainst($post, ReportReason::Copyright);

    // A source_post is SHARED between users — removing it would take other
    // people's places with it. Copyright complaints about one belong in the
    // takedown flow, which unpublishes the citing shares instead.
    expect(app(ReportActions::class)->takeDown($report, $admin, 'DMCA'))->toBeFalse();

    // Still resolved: there is genuinely nothing further to do here, and
    // leaving it open would mean the queue never drains.
    expect($report->fresh()->status)->toBe(ReportStatus::Resolved)
        ->and(SourcePost::find($post->id))->not->toBeNull();
});

it('leaves an offer alone — it is the venue record, not user content', function () {
    $admin = User::factory()->admin()->create();
    $offer = Offer::factory()->create();
    $report = reportAgainst($offer);

    expect(app(ReportActions::class)->takeDown($report, $admin, 'Looks fake'))->toBeFalse();
    expect(Offer::find($offer->id))->not->toBeNull();
});

it('survives a report whose target was deleted underneath it', function () {
    $admin = User::factory()->admin()->create();
    $share = Share::factory()->create();
    $report = reportAgainst($share);
    $share->delete();

    // The gap between filing and triage is real — the owner can remove their
    // own share in it. Throwing here would wedge the queue on a row nobody can
    // clear.
    expect(app(ReportActions::class)->takeDown($report->fresh(), $admin, 'gone'))->toBeFalse();
    expect($report->fresh()->status)->toBe(ReportStatus::Resolved);
});

it('sweeps the other open reports about the same target', function () {
    $admin = User::factory()->admin()->create();
    $share = Share::factory()->create();
    $other = Share::factory()->create();

    $primary = reportAgainst($share);
    $siblings = collect([ReportReason::Spam, ReportReason::Fraud])
        ->map(fn ($reason) => reportAgainst($share, $reason));
    $unrelated = reportAgainst($other);
    $alreadyClosed = Report::factory()->against($share)->reason(ReportReason::WrongPlace)->resolved()->create();

    app(ReportActions::class)->takeDown($primary, $admin, 'Not food.');
    $swept = app(ReportActions::class)->resolveSiblings($primary->fresh(), $admin, 'Not food.');

    // Six reports about one share are ONE decision. Making an admin close them
    // one at a time is how a queue stops being worked.
    expect($swept)->toBe(2)
        ->and($siblings->every(fn ($r) => $r->fresh()->status === ReportStatus::Resolved))->toBeTrue()
        // A different target is somebody else's decision.
        ->and($unrelated->fresh()->status)->toBe(ReportStatus::Open)
        // And an already-closed one is not re-stamped with a new resolver.
        ->and($alreadyClosed->fresh()->resolved_by_user_id)->not->toBe($admin->id);
});

it('dismisses without touching the content', function () {
    $admin = User::factory()->admin()->create();
    $place = Place::factory()->create(['status' => PlaceStatus::Active]);
    $report = reportAgainst($place);

    app(ReportActions::class)->dismiss($report, $admin, 'Legitimate place.');

    expect($place->fresh()->status)->toBe(PlaceStatus::Active)
        ->and($report->fresh()->status)->toBe(ReportStatus::Dismissed)
        ->and($report->fresh()->resolved_by_user_id)->toBe($admin->id);
});
