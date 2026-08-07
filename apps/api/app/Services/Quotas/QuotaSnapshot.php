<?php

namespace App\Services\Quotas;

use App\Models\Share;
use App\Models\User;
use App\Services\AI\SpendTracker;
use Illuminate\Support\Carbon;

/**
 * What a user has left today (T-051, NFR-12).
 *
 * Surfaced on `GET /me` so the app can say "daily limit reached — resets at X"
 * *before* the tap, rather than turning a 429 into an apology afterwards. A
 * quota the client cannot see is a quota the client can only discover by
 * hitting it.
 *
 * Everything resets at midnight **UTC**, one boundary everywhere: it matches
 * the auto-retry the pipeline uses for a share parked over the AI budget (04
 * §3), and a local-midnight reset would make the answer to "when does this come
 * back" depend on where the user is standing.
 */
class QuotaSnapshot
{
    public function __construct(private readonly SpendTracker $spend) {}

    /**
     * @return array{
     *     shares: array{used: int, limit: int, remaining: int},
     *     ai: array{spent_usd: float, budget_usd: float, remaining_usd: float},
     *     resets_at: string
     * }
     */
    public function for(User $user): array
    {
        $shareLimit = (int) config('quotas.daily.shares');
        $sharesUsed = $this->sharesUsed($user);

        $budget = (float) config('ai.daily_user_budget');
        $spent = $this->spend->todaySpendUsd($user->id);

        return [
            'shares' => [
                'used' => $sharesUsed,
                'limit' => $shareLimit,
                'remaining' => max(0, $shareLimit - $sharesUsed),
            ],
            'ai' => [
                'spent_usd' => round($spent, 4),
                'budget_usd' => round($budget, 4),
                'remaining_usd' => round(max(0, $budget - $spent), 4),
            ],
            'resets_at' => $this->resetsAt()->toIso8601String(),
        ];
    }

    /**
     * Has this user used up today's share allowance?
     *
     * Counts directly rather than through `for()`: this runs on every POST
     * /shares, and the full snapshot also reads the AI spend counter — work the
     * write path has no use for.
     */
    public function sharesExhausted(User $user): bool
    {
        return $this->sharesUsed($user) >= (int) config('quotas.daily.shares');
    }

    /**
     * Shares created by this user since the window opened.
     *
     * Counted from the SHARES table, not a cache counter. The counter is a fast
     * pre-check that a Redis flush can lose; what the app shows a user about
     * their own limit has to be the number we would actually enforce on, and
     * that number is rows.
     */
    private function sharesUsed(User $user): int
    {
        return Share::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $this->windowStart())
            ->count();
    }

    /** Midnight UTC just gone — the start of the current quota day. */
    public function windowStart(): Carbon
    {
        return Carbon::now('UTC')->startOfDay();
    }

    /** The next midnight UTC, which is when everything above goes back to zero. */
    public function resetsAt(): Carbon
    {
        return $this->windowStart()->addDay();
    }
}
