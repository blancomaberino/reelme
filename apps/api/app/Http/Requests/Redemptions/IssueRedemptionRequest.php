<?php

namespace App\Http\Requests\Redemptions;

use App\Services\Redemptions\RedemptionGuards;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/redemptions` (T-043, 03 §2.13) — a diner claims a code.
 *
 * No `authorize()` beyond auth: every anti-fraud rule that decides whether THIS
 * diner may have THIS code lives in {@see RedemptionGuards},
 * because each one needs its own machine-readable reason and a boolean
 * `authorize()` can only say no.
 */
class IssueRedemptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'min:1'],
            /*
             * The referral context: which share the diner navigated from, so
             * last-touch attribution can be frozen onto the row (02 §5).
             *
             * Client-supplied and therefore NOT trusted — a diner could
             * otherwise name any share and redirect the influencer's earnings.
             * RedemptionAttribution re-checks it against the offer's place and
             * falls back to the primary source if it does not hold up.
             */
            'share_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function offerId(): int
    {
        return (int) $this->validated('offer_id');
    }

    public function referralShareId(): ?int
    {
        $shareId = $this->validated('share_id');

        return $shareId === null ? null : (int) $shareId;
    }
}
