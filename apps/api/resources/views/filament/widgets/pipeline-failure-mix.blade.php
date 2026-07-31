@php
    /** @var list<array{reason: string, total: int, share: float}> $rows */
    /** @var string $window */
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        heading="Failure mix"
        :description="'Most common failure codes · ' . $window"
    >
        @if ($rows === [])
            {{-- The one empty state on this dashboard that is good news, so it
                 says so rather than reading like missing data. --}}
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No failures in this window.
            </p>
        @else
            <ul class="space-y-3">
                @foreach ($rows as $row)
                    <li>
                        <div class="flex items-baseline justify-between gap-4 text-sm">
                            <span class="font-mono text-gray-950 dark:text-white">{{ $row['reason'] }}</span>
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">
                                {{ number_format($row['total']) }}
                            </span>
                        </div>
                        {{-- Bars are scaled to the LARGEST code, not to the total: this
                             is a ranking, and scaling to the total flattens every bar
                             into invisibility as soon as failures are spread out. --}}
                        <div
                            class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10"
                            role="presentation"
                        >
                            <div
                                class="h-full rounded-full bg-danger-500"
                                style="width: {{ max($row['share'], 2) }}%"
                            ></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
