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

<x-filament-widgets::widget>
    <x-filament::section
        heading="Stage timings"
        :description="'p50 / p95 duration and failure rate per pipeline stage · ' . $window"
    >
        @if (! $hasData)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No pipeline stage ran in this window.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-4 font-medium">Stage</th>
                            <th class="py-2 pe-4 text-right font-medium">Runs</th>
                            <th class="py-2 pe-4 text-right font-medium">p50</th>
                            <th class="py-2 pe-4 text-right font-medium">p95</th>
                            <th class="py-2 text-right font-medium">Failed</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($rows as $row)
                            {{-- A stage that never ran is dimmed, not hidden: in pipeline
                                 order, a gap is itself the finding. --}}
                            <tr @class(['opacity-50' => $row['runs'] === 0])>
                                <td class="py-2 pe-4 font-medium text-gray-950 dark:text-white">
                                    {{ $row['stage'] }}
                                </td>
                                {{-- tabular-nums keeps the digits in a column so the
                                     eye can compare magnitudes down the table. --}}
                                <td class="py-2 pe-4 text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ number_format($row['runs']) }}
                                </td>
                                <td class="py-2 pe-4 text-right tabular-nums text-gray-950 dark:text-white">
                                    {{ $duration($row['p50']) }}
                                </td>
                                {{-- The gap between p50 and p95 is the real signal, so p95
                                     gets the same weight as p50, not a muted treatment. --}}
                                <td class="py-2 pe-4 text-right tabular-nums text-gray-950 dark:text-white">
                                    {{ $duration($row['p95']) }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    @if ($row['failed'] === 0)
                                        <span class="text-gray-400 dark:text-gray-500">—</span>
                                    @else
                                        {{-- Colour only above a threshold. A table where every
                                             row is coloured communicates nothing. --}}
                                        <x-filament::badge
                                            :color="$row['failureRate'] >= 20 ? 'danger' : 'warning'"
                                            class="inline-flex"
                                        >
                                            {{ number_format($row['failed']) }} · {{ $row['failureRate'] }}%
                                        </x-filament::badge>
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
