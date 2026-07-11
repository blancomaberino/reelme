<?php

namespace App\Providers;

use App\Services\Geo\Geocoder;
use App\Services\Geo\GooglePlacesGeocoder;
use App\Services\Geo\NominatimGeocoder;
use Illuminate\Support\ServiceProvider;

class GeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tests swap in a FakeGeocoder via app()->instance(). Otherwise pick by
        // config: Google Places when a key exists, else keyless Nominatim so the
        // pipeline geocodes out of the box (demo/dev).
        $this->app->singleton(Geocoder::class, function (): Geocoder {
            $driver = (string) config('geo.driver', 'auto');
            // filled() so an empty GOOGLE_PLACES_API_KEY ('' in .env) counts as absent.
            $hasGoogleKey = filled(config('services.google_places.key'));

            $useGoogle = $driver === 'google' || ($driver === 'auto' && $hasGoogleKey);

            return $useGoogle
                ? $this->app->make(GooglePlacesGeocoder::class)
                : $this->app->make(NominatimGeocoder::class);
        });
    }
}
