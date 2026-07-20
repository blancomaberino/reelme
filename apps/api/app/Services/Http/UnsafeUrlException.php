<?php

namespace App\Services\Http;

use RuntimeException;

/**
 * A URL failed the {@see PublicUrlGuard} SSRF vetting — wrong scheme, no host, an
 * unresolvable host, or one that resolves to a private/loopback/link-local
 * address. Extends RuntimeException so existing callers that catch/assert on
 * RuntimeException keep working.
 */
class UnsafeUrlException extends RuntimeException {}
