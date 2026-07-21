<?php

use App\Jobs\Concerns\RecordsStageMetrics;
use App\Models\Share;
use App\Models\ShareStageMetric;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * A minimal pipeline job that opens a stage metric and optionally throws — the
 * same RecordsStageMetrics + TracksStageMetric middleware path the real stage
 * jobs use, isolated so completion/failure is deterministic (T-093).
 */
class StageProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RecordsStageMetrics, SerializesModels;

    public function __construct(public readonly int $shareId, public readonly bool $boom = false) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return $this->stageMetricMiddleware();
    }

    public function handle(): void
    {
        $this->recordStage($this->shareId, 'probe');

        if ($this->boom) {
            throw new RuntimeException('kaboom');
        }
    }
}

it('records running → completed with a duration and attempt on success', function () {
    $share = Share::factory()->create();

    Bus::dispatchSync(new StageProbeJob($share->id));

    $metric = ShareStageMetric::query()->where('share_id', $share->id)->where('stage', 'probe')->sole();
    expect($metric->status)->toBe('completed')
        ->and($metric->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($metric->attempt)->toBe(1);
});

it('records failed with a duration when the stage throws, and re-throws', function () {
    $share = Share::factory()->create();

    expect(fn () => Bus::dispatchSync(new StageProbeJob($share->id, boom: true)))
        ->toThrow(RuntimeException::class, 'kaboom');

    $metric = ShareStageMetric::query()->where('share_id', $share->id)->where('stage', 'probe')->sole();
    expect($metric->status)->toBe('failed')
        ->and($metric->duration_ms)->toBeInt();
});

it('emits entry + exit stage logs carrying share_id', function () {
    Log::spy();
    $share = Share::factory()->create();

    Bus::dispatchSync(new StageProbeJob($share->id));

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $m, array $ctx): bool => $m === 'pipeline.probe.start' && ($ctx['share_id'] ?? null) === $share->id)
        ->once();
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $m, array $ctx): bool => $m === 'pipeline.probe.done'
            && ($ctx['share_id'] ?? null) === $share->id
            && array_key_exists('duration_ms', $ctx))
        ->once();
});

it('leaves the row running when the job is invoked directly (middleware bypassed)', function () {
    // A direct ->handle() (unit-test path) never runs job middleware, so the
    // metric stays "running" exactly as before T-093 — no behavioral surprise.
    $share = Share::factory()->create();

    (new StageProbeJob($share->id))->handle();

    expect(ShareStageMetric::query()->where('share_id', $share->id)->sole()->status)->toBe('running');
});
