<?php

use App\Filament\Resources\AnalysisRuns\Pages\ListAnalysisRuns;
use App\Filament\Resources\AnalysisRuns\Pages\ViewAnalysisRun;
use App\Filament\Resources\Shares\Pages\ListShares;
use App\Filament\Resources\Shares\Pages\ViewShare;
use App\Models\AnalysisRun;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// --- Shares (read-only debugging) ---

it('lists shares with status and failure reason, filterable by status', function () {
    $this->actingAs(User::factory()->admin()->create());

    $failed = Share::factory()->failed()->create(['failure_reason' => 'media_too_large']);
    $published = Share::factory()->published()->create();

    Livewire::test(ListShares::class)
        ->assertCanSeeTableRecords([$failed, $published])
        ->assertSee('media_too_large')
        ->filterTable('status', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$published]);
});

it('shows a share view page with its analysis runs', function () {
    $this->actingAs(User::factory()->admin()->create());

    $share = Share::factory()->review()->create(['review_reason' => 'low_confidence']);
    AnalysisRun::factory()->succeeded()->create(['share_id' => $share->id]);

    Livewire::test(ViewShare::class, ['record' => $share->getKey()])
        ->assertOk()
        ->assertSee('low_confidence');
});

// --- Analysis runs (read-only debugging) ---

it('lists analysis runs filterable by engine and status', function () {
    $this->actingAs(User::factory()->admin()->create());

    $local = AnalysisRun::factory()->succeeded()->create();
    $remote = AnalysisRun::factory()->openrouter()->create();

    Livewire::test(ListAnalysisRuns::class)
        ->assertCanSeeTableRecords([$local, $remote])
        ->filterTable('engine', 'local')
        ->assertCanSeeTableRecords([$local])
        ->assertCanNotSeeTableRecords([$remote]);
});

it('shows a run view page with its result payload and error', function () {
    $this->actingAs(User::factory()->admin()->create());

    $run = AnalysisRun::factory()->succeeded()->create([
        'result_json' => ['place' => ['name' => 'Distinctive Extraction Name']],
    ]);

    Livewire::test(ViewAnalysisRun::class, ['record' => $run->getKey()])
        ->assertOk()
        ->assertSee('Distinctive Extraction Name');
});
