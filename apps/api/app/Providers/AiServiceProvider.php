<?php

namespace App\Providers;

use App\Services\AI\Contracts\AnalysisEngine;
use App\Services\AI\CuratedModels;
use App\Services\AI\LocalEngine;
use App\Services\AI\ModelRouter;
use App\Services\AI\OllamaClient;
use App\Services\AI\OpenRouterClient;
use App\Services\AI\SpendTracker;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OllamaClient::class);
        $this->app->singleton(LocalEngine::class);
        $this->app->singleton(CuratedModels::class);
        $this->app->singleton(SpendTracker::class);

        // The remote engine seam: OpenRouterClient (T-020) behind the same
        // AnalysisEngine contract — swapping this binding needs no ModelRouter
        // change.
        $this->app->singleton(AnalysisEngine::class, OpenRouterClient::class);

        $this->app->singleton(ModelRouter::class, fn ($app) => new ModelRouter(
            local: $app->make(LocalEngine::class),
            remote: $app->make(AnalysisEngine::class),
            curated: $app->make(CuratedModels::class),
            spend: $app->make(SpendTracker::class),
        ));
    }
}
