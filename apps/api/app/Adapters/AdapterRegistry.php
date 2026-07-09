<?php

namespace App\Adapters;

use App\Enums\Platform;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the priority-ordered adapter chain for a canonical URL (04 §2).
 * Chains come from config/ingestion.php; every chain terminates in the
 * configured fallback (ManualUploadAdapter). URL canonicalization is the
 * caller's job (T-016) — the registry only tolerates www/mobile host variants.
 */
class AdapterRegistry
{
    /**
     * @param  array<string, array<int, class-string<SourceAdapter>>>  $chains
     * @param  class-string<SourceAdapter>  $fallback
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $chains,
        private readonly string $fallback,
    ) {}

    public function platformFor(string $canonicalUrl): ?Platform
    {
        $host = strtolower((string) parse_url($canonicalUrl, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return match (true) {
            str_contains($host, 'instagram.com') => Platform::Instagram,
            $host === 'x.com', $host === 't.co', str_contains($host, 'twitter.com') => Platform::X,
            str_contains($host, 'tiktok.com') => Platform::Tiktok,
            str_contains($host, 'youtube.com'), $host === 'youtu.be' => Platform::Youtube,
            default => null,
        };
    }

    /**
     * The ordered chain of adapters that support the URL, always ending in the
     * manual fallback.
     *
     * @return array<int, SourceAdapter>
     */
    public function resolve(string $canonicalUrl): array
    {
        $platform = $this->platformFor($canonicalUrl);
        $classes = $platform !== null ? ($this->chains[$platform->value] ?? []) : [];

        $adapters = [];
        foreach ($classes as $class) {
            $adapter = $this->container->make($class);
            if ($adapter->supports($canonicalUrl)) {
                $adapters[] = $adapter;
            }
        }

        $adapters[] = $this->container->make($this->fallback);

        return $adapters;
    }
}
