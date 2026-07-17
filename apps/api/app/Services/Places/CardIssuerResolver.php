<?php

namespace App\Services\Places;

/**
 * Resolves the display name of a bank / card issuer / wallet from its Instagram
 * `@handle` (T-079). A discount attributed to an issuer only by @mention (no
 * plain-text name) shows "@santander.uy" until this upgrades it to the profile's
 * full_name ("Santander").
 *
 * It is the exact `full_name` the venue locator already mines, so this delegates
 * to {@see InstagramProfileLocator} rather than re-implementing the
 * cookie/normalize/cache/fetch scaffold — never throws (a dead/private profile
 * or missing IG cookie yields null, keeping the @handle fallback), and inherits
 * the locator's per-handle cache.
 */
class CardIssuerResolver
{
    public function __construct(private readonly InstagramProfileLocator $locator) {}

    /** Resolve a handle (with or without `@`) to an issuer display name, or null. */
    public function resolve(string $handle): ?string
    {
        return $this->locator->locate($handle)?->name;
    }
}
