@php
    /** @var list<array<string, mixed>> $candidates */
@endphp

@if (empty($candidates))
    <p class="text-sm text-gray-500 dark:text-gray-400">
        No nearby candidates — nothing within the dedup radius looks like this place.
    </p>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 dark:text-gray-400">
                    <th class="py-2 pe-4 font-medium">Candidate</th>
                    <th class="py-2 pe-4 font-medium">Similarity</th>
                    <th class="py-2 pe-4 font-medium">Distance</th>
                    <th class="py-2 pe-4 font-medium">Status</th>
                    <th class="py-2 pe-4 font-medium">Sources</th>
                    <th class="py-2 font-medium">Address</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($candidates as $candidate)
                    <tr>
                        <td class="py-2 pe-4">
                            <a class="font-medium text-primary-600 underline"
                               href="{{ \App\Filament\Resources\Places\PlaceResource::getUrl('view', ['record' => $candidate['place_id']]) }}">
                                {{ $candidate['name'] }}
                            </a>
                        </td>
                        <td class="py-2 pe-4 tabular-nums">{{ number_format($candidate['similarity'] * 100, 1) }}%</td>
                        <td class="py-2 pe-4 tabular-nums">{{ number_format($candidate['distance_m'], 0) }} m</td>
                        <td class="py-2 pe-4">{{ $candidate['status'] }}</td>
                        <td class="py-2 pe-4 tabular-nums">{{ $candidate['shares_count'] }}</td>
                        <td class="py-2 text-gray-500 dark:text-gray-400">{{ $candidate['address'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
        Use the <strong>Merge into…</strong> action above to fold this place into one of these candidates.
    </p>
@endif
