<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * A run's estimated cost exceeds `AI_MAX_COST_PER_RUN` even on the cheapest
 * curated model (04 §3). The router records the blocked attempt as a failed
 * `analysis_runs` row before throwing; the caller (T-021) maps this to the
 * failure taxonomy `cost_cap_exceeded`.
 */
class CostCapExceeded extends RuntimeException {}
