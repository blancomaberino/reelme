<?php

namespace App\Providers;

use App\Adapters\AdapterRegistry;
use App\Adapters\ManualUploadAdapter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class, function (Container $app) {
            /** @var array<string, mixed> $config */
            $config = (array) ($app['config']->get('ingestion') ?? []);

            return new AdapterRegistry(
                container: $app,
                chains: $config['chains'] ?? [],
                // Default the terminal fallback so a missing/partial config fails
                // with working behaviour, not a TypeError on a null string arg.
                fallback: $config['fallback'] ?? ManualUploadAdapter::class,
            );
        });
    }
}
