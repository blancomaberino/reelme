<?php

namespace App\Services\Geo\Exceptions;

use RuntimeException;

/**
 * A transient geocoding failure — HTTP 4xx/5xx, quota (`OVER_QUERY_LIMIT`), or a
 * provider status that is neither OK nor ZERO_RESULTS. Retryable, and its result
 * is never cached (a legitimate ZERO_RESULTS miss returns null instead and IS
 * cached).
 */
class GeocodeFailed extends RuntimeException {}
