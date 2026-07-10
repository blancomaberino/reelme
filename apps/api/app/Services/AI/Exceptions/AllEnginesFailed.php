<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * Both the local and remote engines failed for a run. The caller (T-021's
 * ExtractPlaceData) maps this to the failure taxonomy
 * (`ollama_unreachable`/`invalid_model_output`). Every attempt that led here has
 * already been persisted as an `analysis_runs` row.
 */
class AllEnginesFailed extends RuntimeException {}
