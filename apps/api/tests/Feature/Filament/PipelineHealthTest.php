<?php

use App\Enums\ShareStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PipelineFailureMix;
use App\Filament\Widgets\PipelineHealthOverview;
use App\Filament\Widgets\PipelineStageDurations;
use App\Jobs\Pipeline;
use App\Models\Share;
use App\Models\ShareStageMetric;
use App\Models\User;
use App\Services\Observability\PipelineHealth;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * The pipeline-health dashboard (T-107). The aggregates are asserted against
 * seeded fixtures directly — including the empty case, which is the state an
 * operator sees most often and the one a naive implementation divides by zero
 * in — and the widgets are then smoke-rendered to prove they consume them.
 */
function metric(int $shareId, string $stage, string $status, ?int $durationMs, ?string $startedAt = null): ShareStageMetric
{
    return ShareStageMetric::create([
        'share_id' => $shareId,
        'stage' => $stage,
        'status' => $status,
        'duration_ms' => $durationMs,
        'started_at' => $startedAt ?? now(),
        'attempt' => 1,
    ]);
}

beforeEach(function () {
    $this->health = app(PipelineHealth::class);
    $this->since = now()->subDay();
});

describe('share status counts', function () {
    it('counts each status in the window and zero-fills the rest', function () {
        Share::factory()->count(3)->published()->create();
        Share::factory()->count(2)->create(['status' => ShareStatus::Failed]);
        Share::factory()->review()->create();

        $counts = $this->health->shareStatusCounts($this->since);

        expect($counts[ShareStatus::Published->value])->toBe(3)
            ->and($counts[ShareStatus::Failed->value])->toBe(2)
            ->and($counts[ShareStatus::Review->value])->toBe(1)
            // Zero-filled, not absent: a status that vanishes from the result
            // set must still read as "none" on the dashboard.
            ->and($counts[ShareStatus::Rejected->value])->toBe(0)
            ->and(array_keys($counts))->toHaveCount(count(ShareStatus::cases()));
    });

    it('excludes shares older than the window', function () {
        Share::factory()->published()->create();
        Share::factory()->published()->create()->forceFill(['created_at' => now()->subDays(3)])->save();

        expect($this->health->shareStatusCounts(now()->subDay())[ShareStatus::Published->value])->toBe(1)
            ->and($this->health->shareStatusCounts(now()->subDays(7))[ShareStatus::Published->value])->toBe(2);
    });

    it('returns all-zero counts when nothing was ingested', function () {
        expect(array_sum($this->health->shareStatusCounts($this->since)))->toBe(0);
    });
});

describe('failure mix', function () {
    it('ranks failure codes by frequency, commonest first', function () {
        Share::factory()->count(3)->create(['status' => ShareStatus::Failed, 'failure_reason' => 'geocode_failed']);
        Share::factory()->count(5)->create(['status' => ShareStatus::Failed, 'failure_reason' => 'fetch_unavailable']);

        expect($this->health->failureMix($this->since))->toBe([
            ['reason' => 'fetch_unavailable', 'total' => 5],
            ['reason' => 'geocode_failed', 'total' => 3],
        ]);
    });

    it('counts a review-parked share, whose failure_reason is just as diagnostic', function () {
        Share::factory()->review()->create(['failure_reason' => 'fetch_unavailable']);

        expect($this->health->failureMix($this->since))->toBe([
            ['reason' => 'fetch_unavailable', 'total' => 1],
        ]);
    });

    it('ignores a stale failure_reason left on a share that went on to publish', function () {
        // A retry that succeeds does not clear the column, so counting by
        // failure_reason alone would report failures that no longer exist.
        Share::factory()->published()->create(['failure_reason' => 'geocode_failed']);

        expect($this->health->failureMix($this->since))->toBe([]);
    });

    it('caps the list at the requested number of codes', function () {
        foreach (['a', 'b', 'c'] as $reason) {
            Share::factory()->create(['status' => ShareStatus::Failed, 'failure_reason' => $reason]);
        }

        expect($this->health->failureMix($this->since, 2))->toHaveCount(2);
    });

    it('returns nothing when there were no failures', function () {
        Share::factory()->published()->create();

        expect($this->health->failureMix($this->since))->toBe([]);
    });
});

describe('stage durations', function () {
    it('computes p50 and p95 per stage from closed rows only', function () {
        $share = Share::factory()->create();
        // 1..100 ms: p50 lands mid-range, p95 near the top — a spread wide
        // enough that swapping the two percentiles would fail.
        foreach (range(1, 100) as $ms) {
            metric($share->id, 'fetch', 'completed', $ms);
        }
        // A stage still running has no duration; treating its NULL as 0 would
        // drag the median down every time the pipeline is busy.
        metric($share->id, 'fetch', 'running', null);

        $fetch = collect($this->health->stageDurations($this->since))->firstWhere('stage', 'fetch');

        expect($fetch['runs'])->toBe(101)
            ->and($fetch['p50'])->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(51)
            ->and($fetch['p95'])->toBeGreaterThanOrEqual(95)->toBeLessThanOrEqual(96);
    });

    it('reports the failure rate per stage', function () {
        $share = Share::factory()->create();
        metric($share->id, 'extract', 'completed', 100);
        metric($share->id, 'extract', 'completed', 200);
        metric($share->id, 'extract', 'failed', 50);
        metric($share->id, 'extract', 'failed', 60);

        $extract = collect($this->health->stageDurations($this->since))->firstWhere('stage', 'extract');

        expect($extract['runs'])->toBe(4)
            ->and($extract['failed'])->toBe(2)
            ->and($extract['failureRate'])->toBe(50.0);
    });

    it('lists every pipeline stage in order, including ones that never ran', function () {
        $share = Share::factory()->create();
        metric($share->id, 'publish', 'completed', 10);

        $rows = $this->health->stageDurations($this->since);

        expect(array_column($rows, 'stage'))
            ->toBe(array_keys(Pipeline::STAGES));

        $unused = collect($rows)->firstWhere('stage', 'fetch');
        expect($unused['runs'])->toBe(0)
            ->and($unused['p50'])->toBeNull()
            // The divide-by-zero an empty window walks straight into.
            ->and($unused['failureRate'])->toBe(0.0);
    });

    it('excludes stages that started before the window', function () {
        $share = Share::factory()->create();
        metric($share->id, 'fetch', 'completed', 100, now()->subDays(3)->toDateTimeString());

        expect(collect($this->health->stageDurations(now()->subDay()))->firstWhere('stage', 'fetch')['runs'])->toBe(0)
            ->and($this->health->stageRunCount(now()->subDay()))->toBe(0)
            ->and($this->health->stageRunCount(now()->subDays(7)))->toBe(1);
    });
});

describe('liveness probes', function () {
    it('reports how long the oldest still-running stage has been running', function () {
        $share = Share::factory()->create();
        metric($share->id, 'transcribe', 'running', null, now()->subMinutes(10)->toDateTimeString());
        metric($share->id, 'fetch', 'running', null, now()->subMinutes(2)->toDateTimeString());
        // A closed stage is not wedged, however old it is.
        metric($share->id, 'publish', 'completed', 5, now()->subHour()->toDateTimeString());

        expect($this->health->oldestRunningStageSeconds())
            ->toBeGreaterThanOrEqual(595)
            ->toBeLessThan(660);
    });

    it('reports null when nothing is running', function () {
        $share = Share::factory()->create();
        metric($share->id, 'fetch', 'completed', 100);

        expect($this->health->oldestRunningStageSeconds())->toBeNull();
    });

    it('sums the configured queues', function () {
        Queue::shouldReceive('size')->andReturn(4);

        $depth = $this->health->queueDepth();

        expect($depth['total'])->toBeGreaterThan(0)
            ->and($depth['queues'])->not->toBeEmpty()
            ->and(array_values($depth['queues']))->each->toBe(4);
    });

    it('degrades to unknown rather than failing when the queue backend is down', function () {
        Queue::shouldReceive('size')->andThrow(new RuntimeException('Connection refused'));

        $depth = $this->health->queueDepth();

        // "Unknown" and "zero" must not look alike: an operator staring at this
        // during an outage needs to know the number is missing, not that the
        // queue is empty.
        expect($depth['total'])->toBeNull()
            ->and(array_values($depth['queues']))->each->toBeNull();
    });
});

describe('the dashboard page', function () {
    it('resolves the window filter, falling back to the default for junk input', function () {
        expect(Dashboard::windowStart(['window' => '1'])->timestamp)
            ->toEqualWithDelta(now()->subHour()->timestamp, 2)
            ->and(Dashboard::windowStart(['window' => '168'])->timestamp)
            ->toEqualWithDelta(now()->subDays(7)->timestamp, 2)
            // A hand-edited query string must not produce an unbounded (or
            // zero-length) window.
            ->and(Dashboard::windowStart(['window' => '9999'])->timestamp)
            ->toEqualWithDelta(now()->subDay()->timestamp, 2)
            ->and(Dashboard::windowStart(null)->timestamp)
            ->toEqualWithDelta(now()->subDay()->timestamp, 2);

        expect(Dashboard::windowLabel(['window' => '1']))->toBe('Last hour')
            ->and(Dashboard::windowLabel(['window' => 'nonsense']))->toBe('Last 24 hours');
    });

    it('renders all three widgets for an admin', function () {
        $share = Share::factory()->create(['status' => ShareStatus::Failed, 'failure_reason' => 'geocode_failed']);
        metric($share->id, 'fetch', 'completed', 1200);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(PipelineHealthOverview::class)->assertOk();
        Livewire::test(PipelineStageDurations::class)
            ->assertOk()
            ->assertSee('fetch')
            ->assertSee('1.2 s'); // formatted, not a raw millisecond count
        Livewire::test(PipelineFailureMix::class)
            ->assertOk()
            ->assertSee('geocode_failed');
    });

    it('is admin-only — the landing page now exposes operational internals', function () {
        // Failure codes and stage timings say what is breaking and how the
        // pipeline is built; that is not a signed-in-user surface.
        // Guest first — `actingAs` persists for the rest of the test.
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
    });

    it('renders its empty state without dividing by zero', function () {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(PipelineHealthOverview::class)->assertOk();
        Livewire::test(PipelineStageDurations::class)
            ->assertOk()
            ->assertSee('No pipeline stage ran in this window');
        Livewire::test(PipelineFailureMix::class)
            ->assertOk()
            ->assertSee('No failures in this window');
    });
});
