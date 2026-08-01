@php
    /** @var list<array{reason: string, total: int, share: float}> $rows */
    /** @var string $window */
@endphp

{{-- `rm-` classes come from resources/views/filament/admin-styles.blade.php. --}}
<x-filament-widgets::widget>
    <x-filament::section
        heading="Failure mix"
        :description="'Most common failure codes · ' . $window"
    >
        @if ($rows === [])
            {{-- The one empty state on this dashboard that is good news, so it
                 says so rather than reading like missing data. --}}
            <p class="rm-note">No failures in this window.</p>
        @else
            <ul class="rm-mix">
                @foreach ($rows as $row)
                    <li>
                        <div class="rm-mix-head">
                            <span class="rm-mix-reason">{{ $row['reason'] }}</span>
                            <span class="rm-mix-count">{{ number_format($row['total']) }}</span>
                        </div>
                        {{-- Bars are scaled to the LARGEST code, not to the total: this
                             is a ranking, and scaling to the total flattens every bar
                             into invisibility as soon as failures are spread out. --}}
                        <div class="rm-mix-track" role="presentation">
                            <div class="rm-mix-fill" style="width: {{ max($row['share'], 2) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
