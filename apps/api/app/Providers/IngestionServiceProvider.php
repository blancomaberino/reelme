<?php

namespace App\Providers;

use App\Adapters\AdapterRegistry;
use App\Adapters\ManualUploadAdapter;
use App\Services\Media\Images\PostImageIngestor;
use App\Services\Media\Images\PostImageResolver;
use App\Services\Media\Images\YtDlpResolver;
use App\Services\Media\RemoteFileFetcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // yt-dlp resolver takes primitive config (bin/timeout/cookies), so the
        // container can't autowire it — bind it explicitly from config.
        $this->app->bind(YtDlpResolver::class, function (Container $app) {
            /** @var array<string, mixed> $cfg */
            $cfg = (array) ($app['config']->get('ingestion.yt_dlp') ?? []);

            return new YtDlpResolver(
                bin: is_string($cfg['bin'] ?? null) ? $cfg['bin'] : 'yt-dlp',
                timeout: (int) ($cfg['timeout'] ?? 45),
                cookiesPath: is_string($cfg['cookies_path'] ?? null) && $cfg['cookies_path'] !== ''
                    ? $cfg['cookies_path']
                    : null,
                enabled: (bool) ($cfg['enabled'] ?? true),
            );
        });

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

        // Post-image ingestion (T-013): build the resolver chain from config so a
        // stronger resolver (yt-dlp carousel, paid IG media API) is a one-line
        // prepend, no pipeline rewrite. First resolver returning URLs wins.
        $this->app->singleton(PostImageIngestor::class, function (Container $app) {
            /** @var array<int, mixed> $classes */
            $classes = (array) ($app['config']->get('ingestion.image_resolvers') ?? []);

            $resolvers = [];
            foreach ($classes as $class) {
                // Skip (don't crash) a misconfigured resolver: a bad class would
                // otherwise fail EVERY photo share at runtime, not just log once.
                if (! is_string($class) || ! is_subclass_of($class, PostImageResolver::class)) {
                    Log::warning('ingestion.invalid_image_resolver', ['class' => $class]);

                    continue;
                }
                $resolvers[] = $app->make($class);
            }

            return new PostImageIngestor(
                resolvers: $resolvers,
                fetcher: $app->make(RemoteFileFetcher::class),
            );
        });
    }
}
