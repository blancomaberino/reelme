<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * An engine could not be reached at all (health check failed, host down,
 * connection refused). Distinct from GenerationFailed, which means the engine
 * answered but the call could not complete. Both trigger fallback to the next
 * engine; only when every engine is exhausted does the router throw
 * AllEnginesFailed.
 */
class EngineUnavailable extends RuntimeException {}
