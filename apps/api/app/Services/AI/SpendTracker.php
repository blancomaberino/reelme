<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;

/**
 * Per-user daily AI spend counter for the budget guardrail (04 §3). Backed by
 * the cache store (Redis in prod) as integer micro-dollars — an atomic
 * increment, TTL'd to the UTC day boundary so it self-expires. `analysis_runs`
 * remains the source of truth; this is a fast pre-check to avoid a spend a
 * nightly reconcile would otherwise have to unwind.
 */
class SpendTracker
{
    private const SCALE = 1_000_000; // micro-dollars

    public function todaySpendUsd(int $userId): float
    {
        $micros = (int) Cache::get($this->key($userId), 0);

        return $micros / self::SCALE;
    }

    public function record(int $userId, float $costUsd): void
    {
        if ($costUsd <= 0) {
            return;
        }

        $key = $this->key($userId);
        // Set the TTL only on first write today; increment is atomic thereafter.
        Cache::add($key, 0, $this->secondsUntilUtcMidnight());
        Cache::increment($key, (int) round($costUsd * self::SCALE));
    }

    private function key(int $userId): string
    {
        return "ai:spend:{$userId}:".now()->utc()->format('Ymd');
    }

    private function secondsUntilUtcMidnight(): int
    {
        $now = now()->utc();

        return max(1, $now->diffInSeconds($now->copy()->addDay()->startOfDay()));
    }
}
