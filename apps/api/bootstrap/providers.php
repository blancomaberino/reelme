<?php

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\GeoServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\IngestionServiceProvider;

return [
    AiServiceProvider::class,
    AppServiceProvider::class,
    AdminPanelProvider::class,
    GeoServiceProvider::class,
    HorizonServiceProvider::class,
    IngestionServiceProvider::class,
];
