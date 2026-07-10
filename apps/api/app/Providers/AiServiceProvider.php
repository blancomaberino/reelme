<?php

namespace App\Providers;

use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\LocalEngine;
use App\Services\AI\ModelRouter;
use App\Services\AI\NullRemoteEngine;
use App\Services\AI\OllamaClient;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OllamaClient::class);
        $this->app->singleton(LocalEngine::class);

        // The remote engine seam: NullRemoteEngine now, OpenRouterClient in T-020
        // — swapping this binding needs no ModelRouter change.
        $this->app->singleton(AnalysisEngine::class, NullRemoteEngine::class);

        $this->app->singleton(ModelRouter::class, fn ($app) => new ModelRouter(
            local: $app->make(LocalEngine::class),
            remote: $app->make(AnalysisEngine::class),
        ));
    }
}
