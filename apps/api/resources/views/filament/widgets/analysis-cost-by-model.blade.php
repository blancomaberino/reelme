@php
    /** @var list<array{model: string, engine: string, runs: int, cost_usd: float, avg_confidence: float|null}> $models */
    /** @var list<array{user_id: int, username: string|null, cost_usd: float, runs: int}> $spenders */
    /** @var string $window */
@endphp

{{-- `rm-` classes come from resources/views/filament/admin-styles.blade.php. --}}
<x-filament-widgets::widget>
    <x-filament::section
        heading="Analysis cost breakdown"
        :description="'By model, and who is spending · ' . $window"
    >
        @if ($models === [])
            <p class="rm-note">No analysis runs in this window.</p>
        @else
            <table class="rm-table">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th>Engine</th>
                        <th class="rm-num">Runs</th>
                        <th class="rm-num">Cost</th>
                        {{-- Confidence sits beside cost on purpose: a cheap model
                             that extracts badly is not cheap, it just moves the
                             cost to the review queue. --}}
                        <th class="rm-num">Avg confidence</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($models as $row)
                        <tr>
                            <td>{{ $row['model'] }}</td>
                            <td>{{ $row['engine'] }}</td>
                            <td class="rm-num">{{ number_format($row['runs']) }}</td>
                            <td class="rm-num">${{ number_format($row['cost_usd'], 4) }}</td>
                            <td class="rm-num">
                                {{ $row['avg_confidence'] === null ? '—' : number_format($row['avg_confidence'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($spenders !== [])
                <h4 class="rm-subhead">Top spenders</h4>
                <table class="rm-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th class="rm-num">Runs</th>
                            <th class="rm-num">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($spenders as $row)
                            <tr>
                                {{-- A purged account keeps its ledger rows but loses its
                                     handle (T-050), so the id is the fallback identity. --}}
                                <td>{{ $row['username'] ? '@' . $row['username'] : '#' . $row['user_id'] }}</td>
                                <td class="rm-num">{{ number_format($row['runs']) }}</td>
                                <td class="rm-num">${{ number_format($row['cost_usd'], 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
