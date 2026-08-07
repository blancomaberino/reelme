<?php

use App\Enums\PlaceStatus;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ShareStatus;
use App\Enums\TakedownStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Takedowns\Pages\ListTakedownRequests;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Report;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\TakedownRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The admin surfaces (T-049).
 *
 * A moderation service nobody can reach is not moderation. These drive the real
 * Filament pages rather than the services underneath them, because the gap
 * between "the action works" and "an admin can press it" is exactly where this
 * feature would fail a store review — and the services already have their own
 * tests.
 */
it('gates the moderation queue to admins', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/reports')
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/reports')
        ->assertOk();
});

it('lists open reports and hides resolved ones by default', function () {
    $this->actingAs(User::factory()->admin()->create());

    $open = Report::factory()->create();
    $closed = Report::factory()->resolved()->create();

    Livewire::test(ListReports::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$closed]);
});

it('takes a share down from the queue, with the note recorded', function () {
    Storage::fake(config('media.disk'));
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $post = SourcePost::factory()->create();
    $place = Place::factory()->create(['status' => PlaceStatus::Active]);
    $share = Share::factory()->published()->create(['source_post_id' => $post->id]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'source_post_id' => $post->id,
        'published_at' => now(),
    ]);
    $report = Report::factory()->against($share)->reason(ReportReason::Inappropriate)->create();

    // The real control, with the real form. A service test proves the take-down
    // works; this proves somebody can actually trigger it.
    Livewire::test(ListReports::class)
        ->callTableAction('take_down', $report, ['note' => 'Not food content.', 'sweep' => false]);

    expect($share->fresh()->status)->toBe(ShareStatus::Rejected)
        ->and($report->fresh()->status)->toBe(ReportStatus::Resolved)
        ->and($report->fresh()->resolved_by_user_id)->toBe($admin->id);
});

it('refuses to act without a note', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $report = Report::factory()->create();

    // The note is what a takedown dispute or a store appeal is answered with.
    // An action that can be taken without one leaves an audit trail of bare
    // timestamps, which answers nothing.
    Livewire::test(ListReports::class)
        ->callTableAction('dismiss', $report, ['note' => ''])
        ->assertHasTableActionErrors(['note']);

    expect($report->fresh()->status)->toBe(ReportStatus::Open);
});

it('sweeps sibling reports when asked', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $share = Share::factory()->create();
    $primary = Report::factory()->against($share)->reason(ReportReason::Spam)->create();
    $sibling = Report::factory()->against($share)->reason(ReportReason::Fraud)->create();

    Livewire::test(ListReports::class)
        ->callTableAction('dismiss', $primary, ['note' => 'Fine actually.', 'sweep' => true]);

    expect($sibling->fresh()->status)->toBe(ReportStatus::Dismissed);
});

it('actually bans from the queue, and records the note', function () {
    Log::spy();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $offender = User::factory()->create();
    $offender->createToken('phone');
    $report = Report::factory()->against($offender)->reason(ReportReason::Fraud)->create();

    // The ban action's BODY had no test — only its visibility. Swapping
    // `banReported` for `dismiss` in the table left every test on the branch
    // green while the most destructive control in the product banned nobody.
    Livewire::test(ListReports::class)
        ->callTableAction('ban', $report, ['note' => 'Impersonating a venue.', 'sweep' => false]);

    $offender = User::withTrashed()->find($offender->id);

    expect($offender->trashed())->toBeTrue()
        ->and($offender->tokens()->count())->toBe(0)
        ->and($report->fresh()->status)->toBe(ReportStatus::Resolved);

    // The note is the audit trail this design justifies itself with. Without
    // this the whole Log::info block could be deleted and nothing would fail.
    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context) => $message === 'moderation.report.actioned'
            && $context['action'] === 'ban'
            && $context['note'] === 'Impersonating a venue.'
            && $context['admin_id'] === $admin->id,
    );
});

it('says so instead of silently doing nothing when a ban cannot apply', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    // A report filed against the admin themselves. `visible()` does not exclude
    // it, so the button is there — and before this, pressing it did nothing at
    // all AND still swept the siblings into a half-resolved state.
    $report = Report::factory()->against($admin)->create();
    $sibling = Report::factory()->against($admin)->reason(ReportReason::Spam)->create();

    Livewire::test(ListReports::class)
        ->callTableAction('ban', $report, ['note' => 'oops', 'sweep' => true]);

    expect($admin->fresh()->trashed())->toBeFalse()
        ->and($report->fresh()->status)->toBe(ReportStatus::Open)
        // The sweep must NOT have run: it copies the primary's status onto the
        // siblings, and the primary is still open — so it would stamp a
        // resolver onto rows that stay in the queue looking decided.
        ->and($sibling->fresh()->status)->toBe(ReportStatus::Open)
        ->and($sibling->fresh()->resolved_by_user_id)->toBeNull();
});

it('leaves siblings alone when the admin turns the sweep off', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $share = Share::factory()->create();
    $primary = Report::factory()->against($share)->reason(ReportReason::Spam)->create();
    $sibling = Report::factory()->against($share)->reason(ReportReason::Fraud)->create();

    // The toggle defaults to on, so OFF is the deliberate choice of a careful
    // admin — and it was the untested direction.
    Livewire::test(ListReports::class)
        ->callTableAction('dismiss', $primary, ['note' => 'Only this one.', 'sweep' => false]);

    expect($primary->fresh()->status)->toBe(ReportStatus::Dismissed)
        ->and($sibling->fresh()->status)->toBe(ReportStatus::Open);
});

it('only offers Ban when the report is against a user', function () {
    $this->actingAs(User::factory()->admin()->create());

    $againstUser = Report::factory()->against(User::factory()->create())->create();
    $againstShare = Report::factory()->against(Share::factory()->create())->reason(ReportReason::Spam)->create();

    Livewire::test(ListReports::class)
        ->assertTableActionVisible('ban', $againstUser)
        ->assertTableActionHidden('ban', $againstShare);
});

it('lets an admin log and action a takedown notice', function () {
    Storage::fake(config('media.disk'));
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $post = SourcePost::factory()->create();
    $share = Share::factory()->published()->create(['source_post_id' => $post->id]);
    $place = Place::factory()->create(['status' => PlaceStatus::Active]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'source_post_id' => $post->id,
        'published_at' => now(),
    ]);
    $request = TakedownRequest::factory()->forPost($post)->create();

    Livewire::test(ListTakedownRequests::class)->callTableAction('process', $request);

    expect($share->fresh()->status)->toBe(ShareStatus::Rejected)
        ->and($request->fresh()->status)->toBe(TakedownStatus::Actioned)
        // FR-30 — the pin survives its source being pulled.
        ->and(Place::find($place->id))->not->toBeNull();
});

it('keeps takedowns out of non-admin hands', function () {
    // The takedown surface can unpublish other people's places; it must be as
    // closed to a signed-in non-admin as it is to the public.
    $this->actingAs(User::factory()->create())
        ->get('/admin/takedowns/takedown-requests')
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin/takedowns/takedown-requests')
        ->assertOk();
});
