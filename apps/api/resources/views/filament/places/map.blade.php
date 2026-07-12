@php
    /** @var array{lat: float, lng: float} $coordinates */
    $lat = $coordinates['lat'];
    $lng = $coordinates['lng'];
    $delta = 0.003;
    $bbox = implode(',', [$lng - $delta, $lat - $delta, $lng + $delta, $lat + $delta]);
@endphp

<div class="space-y-2">
    <iframe
        title="Map"
        class="w-full rounded-lg border-0"
        style="height: 260px"
        loading="lazy"
        referrerpolicy="no-referrer"
        sandbox="allow-scripts allow-same-origin"
        src="https://www.openstreetmap.org/export/embed.html?bbox={{ urlencode($bbox) }}&layer=mapnik&marker={{ urlencode($lat . ',' . $lng) }}"
    ></iframe>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ number_format($lat, 6) }}, {{ number_format($lng, 6) }}
        · <a class="text-primary-600 underline" target="_blank" rel="noopener"
             href="https://www.openstreetmap.org/?mlat={{ $lat }}&mlon={{ $lng }}#map=18/{{ $lat }}/{{ $lng }}">Open in OSM</a>
    </p>
</div>
