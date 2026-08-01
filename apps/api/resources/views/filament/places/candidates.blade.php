@php
    /** @var list<array<string, mixed>> $candidates */
@endphp

{{-- `rm-` classes come from resources/views/filament/admin-styles.blade.php.
     This view previously used Tailwind utilities, which a Filament v5 panel does
     not ship: the table rendered with no cell padding and no row dividers. --}}

@if (empty($candidates))
    <p class="rm-note">
        No nearby candidates — nothing within the dedup radius looks like this place.
    </p>
@else
    <div class="rm-scroll">
        <table class="rm-table">
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Similarity</th>
                    <th>Distance</th>
                    <th>Status</th>
                    <th>Sources</th>
                    <th>Address</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($candidates as $candidate)
                    <tr>
                        <td>
                            <a class="rm-link rm-strong"
                               href="{{ \App\Filament\Resources\Places\PlaceResource::getUrl('view', ['record' => $candidate['place_id']]) }}">
                                {{ $candidate['name'] }}
                            </a>
                        </td>
                        <td class="rm-num">{{ number_format($candidate['similarity'] * 100, 1) }}%</td>
                        <td class="rm-num">{{ number_format($candidate['distance_m'], 0) }} m</td>
                        <td>{{ $candidate['status'] }}</td>
                        <td class="rm-num">{{ $candidate['shares_count'] }}</td>
                        <td class="rm-muted">{{ $candidate['address'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="rm-footnote">
        Use the <strong>Merge into…</strong> action above to fold this place into one of these candidates.
    </p>
@endif
