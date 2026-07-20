<?php

namespace App\Adapters;

use App\Enums\Platform;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the priority-ordered adapter chain for a canonical URL (04 §2).
 * Chains come from config/ingestion.php; every chain terminates in the
 * configured fallback (ManualUploadAdapter). URL canonicalization is the
 * caller's job (T-016) — the registry accepts subdomain variants (www./m./vm.)
 * via suffix-anchored host matching but rejects look-alike domains.
 */
class AdapterRegistry
{
    /**
     * @param  array<string, array<int, class-string<SourceAdapter>>>  $chains
     * @param  class-string<SourceAdapter>  $fallback
     * @param  array<string, array{enabled?: bool}>  $platforms  Per-platform kill switches (config ingestion.platforms); a disabled platform resolves to manual-only (T-014).
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $chains,
        private readonly string $fallback,
        private readonly array $platforms = [],
    ) {}

    public function platformFor(string $canonicalUrl): ?Platform
    {
        $host = strtolower((string) parse_url($canonicalUrl, PHP_URL_HOST));

        // Suffix-anchored: exact host or a dot-subdomain of it. NEVER a bare
        // substring — `instagram.com.evil.com` / `notinstagram.com` are
        // attacker domains and must not be classified as a trusted platform
        // (that would route them to a real adapter + the user's token, T-013+).
        $isHost = fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain);

        return match (true) {
            $isHost('instagram.com') => Platform::Instagram,
            $isHost('x.com'), $isHost('t.co'), $isHost('twitter.com') => Platform::X,
            $isHost('tiktok.com') => Platform::Tiktok,
            $isHost('youtube.com'), $isHost('youtu.be') => Platform::Youtube,
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

        // A per-platform kill switch (config ingestion.platforms.<p>.enabled)
        // forces the whole platform to manual-only without a deploy: skip EVERY
        // real adapter — metadata AND yt-dlp — so "disabled" honestly means
        // manual-only (T-014, 01 §5). Unknown/unconfigured platforms default on.
        $classes = $platform !== null && $this->platformEnabled($platform)
            ? ($this->chains[$platform->value] ?? [])
            : [];

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

    /**
     * Whether a platform is enabled (config ingestion.platforms.<p>.enabled);
     * defaults on when unconfigured. The single source of truth shared by
     * resolve() (chain gating) and share acceptance (ShareController).
     */
    public function platformEnabled(Platform $platform): bool
    {
        return (bool) ($this->platforms[$platform->value]['enabled'] ?? true);
    }
}
