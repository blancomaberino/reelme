<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * The engine was reachable but the generation itself failed — non-2xx response,
 * request timeout, or an unparseable envelope. Triggers fallback to the next
 * engine (04 §3, reason `ollama_error` for the local engine).
 */
class GenerationFailed extends RuntimeException {}
