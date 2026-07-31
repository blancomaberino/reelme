@php
    /** @var array{lat: float, lng: float} $coordinates */
    $lat = $coordinates['lat'];
    $lng = $coordinates['lng'];
    $delta = 0.003;
    $bbox = implode(',', [$lng - $delta, $lat - $delta, $lng + $delta, $lat + $delta]);
@endphp

{{-- `rm-` classes come from resources/views/filament/admin-styles.blade.php.
     `w-full` used to be a Tailwind utility here, which the panel does not ship —
     so the iframe fell back to its intrinsic 300px instead of filling the pane. --}}

<div class="rm-stack">
    <iframe
        title="Map"
        class="rm-map"
        loading="lazy"
        referrerpolicy="no-referrer"
        sandbox="allow-scripts allow-same-origin"
        src="https://www.openstreetmap.org/export/embed.html?bbox={{ urlencode($bbox) }}&layer=mapnik&marker={{ urlencode($lat . ',' . $lng) }}"
    ></iframe>
    <p class="rm-note">
        {{ number_format($lat, 6) }}, {{ number_format($lng, 6) }}
        · <a class="rm-link" target="_blank" rel="noopener"
             href="https://www.openstreetmap.org/?mlat={{ $lat }}&mlon={{ $lng }}#map=18/{{ $lat }}/{{ $lng }}">Open in OSM</a>
    </p>
</div>
