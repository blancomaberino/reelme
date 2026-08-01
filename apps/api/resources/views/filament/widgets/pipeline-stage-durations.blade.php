@php
    /** @var list<array{stage: string, runs: int, failed: int, failureRate: float, p50: int|null, p95: int|null}> $rows */
    /** @var bool $hasData */
    /** @var string $window */

    // Durations are read by comparing them to each other, so they are formatted
    // to one consistent unit-per-magnitude rather than "1250 ms" next to "3 m".
    $duration = function (?int $ms): string {
        if ($ms === null) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms . ' ms';
        }
        if ($ms < 60_000) {
            return round($ms / 1000, 1) . ' s';
        }

        return round($ms / 60_000, 1) . ' min';
    };
@endphp

{{-- `rm-` classes come from resources/views/filament/admin-styles.blade.php,
     which the panel injects once per page. Tailwind utilities do NOT work in a
     Filament v5 panel — that file explains why. --}}
<x-filament-widgets::widget>
    <x-filament::section
        heading="Stage timings"
        :description="'p50 / p95 duration and failure rate per pipeline stage · ' . $window"
    >
        @if (! $hasData)
            <p class="rm-note">No pipeline stage ran in this window.</p>
        @else
            <div class="rm-scroll">
                <table class="rm-table">
                    <thead>
                        <tr>
                            <th>Stage</th>
                            <th class="rm-right">Runs</th>
                            <th class="rm-right">p50</th>
                            <th class="rm-right">p95</th>
                            <th class="rm-right">Failed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr @class(['rm-idle' => $row['runs'] === 0])>
                                <td class="rm-strong">{{ $row['stage'] }}</td>
                                {{-- tabular-nums keeps the digits in a column so the
                                     eye can compare magnitudes down the table. --}}
                                <td class="rm-num rm-right rm-muted">{{ number_format($row['runs']) }}</td>
                                <td class="rm-num rm-right rm-strong">{{ $duration($row['p50']) }}</td>
                                {{-- The gap between p50 and p95 is the real signal, so p95
                                     gets the same weight as p50, not a muted treatment. --}}
                                <td class="rm-num rm-right rm-strong">{{ $duration($row['p95']) }}</td>
                                <td class="rm-num rm-right">
                                    @if ($row['failed'] === 0)
                                        <span class="rm-none">—</span>
                                    @else
                                        {{-- Colour only above a threshold. A table where every
                                             row is coloured communicates nothing. --}}
                                        <span @class([
                                            'rm-pill',
                                            'rm-pill-danger' => $row['failureRate'] >= 20,
                                            'rm-pill-warn' => $row['failureRate'] < 20,
                                        ])>
                                            {{ number_format($row['failed']) }} · {{ $row['failureRate'] }}%
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
