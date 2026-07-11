<?php

namespace App\Services\AI\Exceptions;

use RuntimeException;

/**
 * The user is over their daily budget (`AI_DAILY_USER_BUDGET`) and the local
 * engine could not serve the run either, so no engine is permitted (04 §3). The
 * caller (T-021) parks the share in `review` with `review_reason:
 * quota_exhausted`, which auto-retries after midnight UTC when the counter
 * resets.
 */
class QuotaExhausted extends RuntimeException {}
