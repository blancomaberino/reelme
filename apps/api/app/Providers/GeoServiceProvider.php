<?php

namespace App\Providers;

use App\Services\Geo\Geocoder;
use App\Services\Geo\GooglePlacesGeocoder;
use Illuminate\Support\ServiceProvider;

class GeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Production binding; tests swap in a FakeGeocoder via app()->instance().
        $this->app->singleton(Geocoder::class, GooglePlacesGeocoder::class);
    }
}
