<?php

use App\Enums\AnalysisEngine;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AnalysisCostByModel;
use App\Filament\Widgets\AnalysisCostChart;
use App\Filament\Widgets\AnalysisCostOverview;
use App\Models\AnalysisRun;
use App\Models\Share;
use App\Models\User;
use App\Services\Observability\AnalysisCosts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * The AI cost dashboard (T-051, NFR-13, 04 §8).
 *
 * These aggregates are what somebody decides a budget on, so the tests are
 * mostly about the numbers being *right* rather than merely present — an
 * average that quietly includes unfinished runs, or a fallback rate that reads
 * 0% when the pipeline has stopped, is worse than no widget at all.
 */
beforeEach(function () {
    Cache::flush();
});

function costRun(AnalysisEngine $engine, float $cost, ?Carbon $finishedAt = null, ?User $user = null, ?float $confidence = null, string $model = 'gemma3:12b'): AnalysisRun
{
    $share = Share::factory()->create($user ? ['user_id' => $user->id] : []);

    return AnalysisRun::factory()->create([
        'share_id' => $share->id,
        'engine' => $engine,
        'model' => $model,
        'cost_usd' => $cost,
        'overall_confidence' => $confidence,
        'finished_at' => $finishedAt ?? now(),
    ]);
}

it('sums spend and computes the remote fallback rate', function () {
    costRun(AnalysisEngine::Local, 0.0);
    costRun(AnalysisEngine::Local, 0.0);
    costRun(AnalysisEngine::OpenRouter, 0.04);
    costRun(AnalysisEngine::OpenRouter, 0.06);

    $summary = app(AnalysisCosts::class)->summary(now()->subDay());

    // 04 §8: the fallback rate is the share of runs that went to the PAID
    // engine. It is the number that tells you local inference is failing while
    // everything still looks like it is working.
    expect($summary['spend_usd'])->toBe(0.1)
        ->and($summary['runs'])->toBe(4)
        ->and($summary['fallback_rate'])->toBe(0.5)
        ->and($summary['avg_cost_usd'])->toBe(0.025);
});

it('reports no fallback rate at all when nothing ran', function () {
    $summary = app(AnalysisCosts::class)->summary(now()->subDay());

    // NOT 0.0. "0% fallback" and "the pipeline has stopped" are different
    // states, and a confident zero would hide the second one behind a
    // reassuring green number.
    expect($summary['runs'])->toBe(0)
        ->and($summary['fallback_rate'])->toBeNull()
        ->and($summary['avg_cost_usd'])->toBeNull();
});

it('ignores runs that never finished', function () {
    costRun(AnalysisEngine::OpenRouter, 0.10);
    AnalysisRun::factory()->create([
        'share_id' => Share::factory()->create()->id,
        'engine' => AnalysisEngine::OpenRouter,
        'cost_usd' => 99.0,
        'finished_at' => null,
    ]);

    // An in-flight run has no final cost. Counting one would make the average
    // jump around for reasons that have nothing to do with spending.
    expect(app(AnalysisCosts::class)->summary(now()->subDay())['spend_usd'])->toBe(0.1);
});

it('respects the window boundary', function () {
    costRun(AnalysisEngine::OpenRouter, 0.10, now()->subHours(2));
    costRun(AnalysisEngine::OpenRouter, 5.00, now()->subDays(9));

    expect(app(AnalysisCosts::class)->summary(now()->subDay())['spend_usd'])->toBe(0.1);
});

it('gives every day in the chart a point, including the empty ones', function () {
    costRun(AnalysisEngine::OpenRouter, 0.20, Carbon::now('UTC')->startOfDay());

    $data = app(AnalysisCosts::class)->dailyByEngine(7);

    // A missing day makes a gap look like a plateau, which hides exactly the
    // outage somebody is scanning this chart for.
    expect($data['labels'])->toHaveCount(7)
        ->and($data['series'])->toHaveKeys(['local', 'openrouter'])
        ->and($data['series']['openrouter'])->toHaveCount(7)
        ->and(end($data['series']['openrouter']))->toBe(0.2)
        ->and($data['series']['openrouter'][0])->toBe(0.0);
});

it('breaks cost down by model, most expensive first', function () {
    costRun(AnalysisEngine::OpenRouter, 0.30, null, null, 0.8, 'gemini-2.0-flash');
    costRun(AnalysisEngine::Local, 0.0, null, null, 0.6, 'gemma3:12b');
    costRun(AnalysisEngine::Local, 0.0, null, null, 0.4, 'gemma3:12b');

    $rows = app(AnalysisCosts::class)->byModel(now()->subDay());

    expect($rows[0]['model'])->toBe('gemini-2.0-flash')
        ->and($rows[0]['cost_usd'])->toBe(0.3)
        ->and($rows[1]['model'])->toBe('gemma3:12b')
        ->and($rows[1]['runs'])->toBe(2)
        // Confidence sits beside cost because a cheap model that extracts badly
        // is not cheap — it just moves the cost into the review queue.
        ->and($rows[1]['avg_confidence'])->toBe(0.5);
});

it('names the accounts spending the most', function () {
    $heavy = User::factory()->create(['username' => 'heavy']);
    $light = User::factory()->create(['username' => 'light']);
    costRun(AnalysisEngine::OpenRouter, 0.50, null, $heavy);
    costRun(AnalysisEngine::OpenRouter, 0.01, null, $light);

    $rows = app(AnalysisCosts::class)->topSpenders(now()->subDay());

    // "Which model is expensive" and "who is spending" are different answers,
    // and only the second one catches a single account with a script.
    expect($rows[0]['username'])->toBe('heavy')
        ->and($rows[0]['cost_usd'])->toBe(0.5)
        ->and($rows[1]['username'])->toBe('light');
});

it('actually puts the cost widgets ON the dashboard', function () {
    // `Dashboard::getWidgets()` is an EXPLICIT list, so panel-level
    // auto-discovery does not reach it. A widget that renders perfectly in its
    // own Livewire test and is missing from that list simply never appears —
    // indistinguishable from not existing, and green either way. This is the
    // assertion that catches it.
    $widgets = (new Dashboard)->getWidgets();

    expect($widgets)->toContain(AnalysisCostOverview::class)
        ->toContain(AnalysisCostChart::class)
        ->toContain(AnalysisCostByModel::class);
});

it('renders the cost widgets on the admin dashboard', function () {
    $this->actingAs(User::factory()->admin()->create());
    costRun(AnalysisEngine::OpenRouter, 0.25, null, null, 0.9);

    // A widget that throws on real data is a dashboard nobody can open — and
    // the aggregate tests above would still be green.
    Livewire::test(AnalysisCostOverview::class)->assertOk();
    Livewire::test(AnalysisCostChart::class)->assertOk();
    Livewire::test(AnalysisCostByModel::class)->assertOk()->assertSee('gemma3:12b');
});

it('shows the cost widgets when an admin opens /admin', function () {
    costRun(AnalysisEngine::OpenRouter, 0.25, null, null, 0.9);

    // The end of the seam: the widget class exists, renders, IS in the
    // dashboard's list — and the page an admin actually opens mounts it.
    //
    // Asserted on the COMPONENT reference, not on the heading: Filament
    // lazy-loads widgets, so the first response carries `wire:snapshot`
    // placeholders and the rendered text only arrives on hydration. (The
    // pipeline widgets behave the same way — checked, not assumed.)
    $this->actingAs(User::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('AnalysisCostOverview', escape: false)
        ->assertSee('AnalysisCostChart', escape: false)
        ->assertSee('AnalysisCostByModel', escape: false);
});

it('caches the aggregates so the dashboard cannot hammer Postgres', function () {
    costRun(AnalysisEngine::OpenRouter, 0.10);
    $costs = app(AnalysisCosts::class);

    expect($costs->summary(now()->subDay())['spend_usd'])->toBe(0.1);

    costRun(AnalysisEngine::OpenRouter, 5.00);

    // A dashboard that recomputes a 30-day group-by on every poll makes the
    // database slower the more closely it is being watched.
    expect($costs->summary(now()->subDay())['spend_usd'])->toBe(0.1);

    Cache::flush();
    expect($costs->summary(now()->subDay())['spend_usd'])->toBe(5.1);
});

it('still caches when the window start moves, which is every single call', function () {
    costRun(AnalysisEngine::OpenRouter, 0.10);
    $costs = app(AnalysisCosts::class);

    expect($costs->summary(now()->subDay())['spend_usd'])->toBe(0.1);

    costRun(AnalysisEngine::OpenRouter, 5.00);
    $this->travel(1)->second();

    // The regression this exists for: the key was `$since->timestamp`, and
    // `$since` is `now()->subDay()` — so it changed EVERY SECOND and every
    // lookup was a miss plus a write into an unbounded key space. The docblock
    // promised caching; the widget recomputed a 30-day group-by on every poll.
    // Without the minute bucket the assertion below reads 5.1.
    expect($costs->summary(now()->subDay())['spend_usd'])->toBe(0.1);
});
