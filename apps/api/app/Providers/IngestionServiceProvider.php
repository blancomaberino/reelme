<?php

namespace App\Providers;

use App\Adapters\AdapterRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class, function (Container $app) {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('ingestion');

            return new AdapterRegistry(
                container: $app,
                chains: $config['chains'] ?? [],
                fallback: $config['fallback'],
            );
        });
    }
}
