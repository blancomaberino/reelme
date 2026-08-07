<?php

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Report;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * POST /reports (T-049, 03 §2.16).
 *
 * This endpoint is a store-review requirement (Apple 1.2, Google UGC), so the
 * bar is not "it writes a row" — it is that a real user can flag a real thing
 * and that the queue on the other side stays usable. Most of what follows is
 * about the second half: a report that names nothing, or a thousand reports
 * from one person, are both ways of making moderation impossible.
 */
it('files a report against a share', function () {
    $user = User::factory()->create();
    $share = Share::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/reports', [
        'reportable_type' => 'share',
        'reportable_id' => $share->id,
        'reason' => ReportReason::Inappropriate->value,
        'details' => 'Nothing to do with food.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.report.reason', 'inappropriate')
        ->assertJsonPath('data.report.status', 'open')
        ->assertJsonPath('data.report.details', 'Nothing to do with food.');

    $report = Report::sole();
    expect($report->reporter_user_id)->toBe($user->id)
        // The ALIAS on disk, not the FQCN — a report stored under a class name
        // is invisible to every query the moderation queue makes.
        ->and($report->reportable_type)->toBe('share')
        ->and($report->reportable_id)->toBe($share->id)
        ->and($report->reportable->is($share))->toBeTrue();
});

it('accepts every reportable type the spec lists', function (string $type) {
    $user = User::factory()->create();

    $target = match ($type) {
        'place' => Place::factory()->create(),
        'share' => Share::factory()->create(),
        'user' => User::factory()->create(),
        'source_post' => SourcePost::factory()->create(),
        'offer' => Offer::factory()->create(),
    };

    // 03 §2.16 and 02 §3.17 list different sets (`offer` vs `source_post`); the
    // union is supported, and this is what keeps the morph map, the validation
    // allowlist and the actual tables agreeing.
    $this->actingAs($user)->postJson('/api/v1/reports', [
        'reportable_type' => $type,
        'reportable_id' => $target->getKey(),
        'reason' => ReportReason::Spam->value,
    ])->assertCreated();

    expect(Report::sole()->reportable->is($target))->toBeTrue();
})->with(['place', 'share', 'user', 'source_post', 'offer']);

it('rejects a type nobody can report', function () {
    $user = User::factory()->create();

    // `influencer` IS in the morph map (follows use it), which is exactly why
    // the allowlist is separate: an influencer identity is public business data
    // and complaints about it route through the takedown flow, not this queue.
    $this->actingAs($user)->postJson('/api/v1/reports', [
        'reportable_type' => 'influencer',
        'reportable_id' => 1,
        'reason' => ReportReason::Spam->value,
    ])->assertStatus(422)->assertJsonPath('error.details.reportable_type', fn ($v) => is_array($v));

    expect(Report::count())->toBe(0);
});

it('rejects a report against something that does not exist', function () {
    $user = User::factory()->create();

    // The cheapest way to fill a moderation queue with unactionable noise is to
    // point it at ids nobody can open.
    $this->actingAs($user)->postJson('/api/v1/reports', [
        'reportable_type' => 'share',
        'reportable_id' => 999_999,
        'reason' => ReportReason::Spam->value,
    ])->assertStatus(422)->assertJsonPath('error.details.reportable_id', fn ($v) => is_array($v));
});

it('rejects a reason outside the enum', function () {
    $user = User::factory()->create();
    $share = Share::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/reports', [
        'reportable_type' => 'share',
        'reportable_id' => $share->id,
        'reason' => 'i_just_dont_like_it',
    ])->assertStatus(422)->assertJsonPath('error.details.reason', fn ($v) => is_array($v));
});

it('answers 409 when you file the same report twice', function () {
    $user = User::factory()->create();
    $share = Share::factory()->create();

    $payload = [
        'reportable_type' => 'share',
        'reportable_id' => $share->id,
        'reason' => ReportReason::Spam->value,
    ];

    $this->actingAs($user)->postJson('/api/v1/reports', $payload)->assertCreated();
    $second = $this->actingAs($user)->postJson('/api/v1/reports', $payload);

    // 409 rather than a silent 201: "already reported" is different information,
    // and the client shows it ("Already reported — thanks") instead of implying
    // a second flag was filed. One row either way — the queue must not learn to
    // treat one angry user as six.
    $second->assertStatus(409)->assertJsonPath('data.report.id', (string) Report::sole()->id);
    expect(Report::count())->toBe(1);
});

it('lets the same person report the same thing for a different reason', function () {
    $user = User::factory()->create();
    $share = Share::factory()->create();

    foreach ([ReportReason::Spam, ReportReason::Copyright] as $reason) {
        $this->actingAs($user)->postJson('/api/v1/reports', [
            'reportable_type' => 'share',
            'reportable_id' => $share->id,
            'reason' => $reason->value,
        ])->assertCreated();
    }

    // The unique key includes the reason on purpose: "this is spam" and "this
    // is my copyrighted footage" are different complaints with different
    // handling, and collapsing them would lose the second one.
    expect(Report::count())->toBe(2);
});

it('never lets the caller file as someone else or pre-resolve a report', function () {
    $user = User::factory()->create();
    $victim = User::factory()->create();
    $share = Share::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/reports', [
        'reportable_type' => 'share',
        'reportable_id' => $share->id,
        'reason' => ReportReason::Spam->value,
        // Both guarded off `$fillable`. The first would let anyone poison
        // another account's reporting history; the second would let a report
        // arrive already dismissed, i.e. never seen by a human.
        'reporter_user_id' => $victim->id,
        'status' => ReportStatus::Dismissed->value,
        'resolved_by_user_id' => $victim->id,
    ])->assertCreated();

    $report = Report::sole();
    expect($report->reporter_user_id)->toBe($user->id)
        ->and($report->status)->toBe(ReportStatus::Open)
        ->and($report->resolved_by_user_id)->toBeNull();
});

it('requires a session', function () {
    $share = Share::factory()->create();

    $this->postJson('/api/v1/reports', [
        'reportable_type' => 'share',
        'reportable_id' => $share->id,
        'reason' => ReportReason::Spam->value,
    ])->assertUnauthorized();
});

it('refuses a reason the database does not know, even past the validator', function () {
    $share = Share::factory()->create();
    $user = User::factory()->create();

    // The CHECK constraint is the backstop for the admin panel, console
    // commands and backfills — every writer that never sees the FormRequest.
    expect(fn () => DB::table('reports')->insert([
        'reporter_user_id' => $user->id,
        'reportable_type' => 'share',
        'reportable_id' => $share->id,
        'reason' => 'whatever',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('answers in the shape the contract promises', function () {
    $user = User::factory()->create();
    $share = Share::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/reports', [
        'reportable_type' => 'share',
        'reportable_id' => $share->id,
        'reason' => ReportReason::Copyright->value,
        'details' => 'Mine.',
    ])->assertCreated();

    // The API Resource, the JSON Schema and the mobile TS each see only one
    // seam; this is the assertion that makes them agree. `additionalProperties:
    // false` means an extra field here fails too — which is the point, since a
    // field added server-side is exactly how triage data leaks to a reporter.
    assertMatchesContract($response->json('data.report'), 'report');
});
