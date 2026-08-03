<?php

namespace App\Http\Requests\Offers;

use App\Enums\OfferDiscountType;
use App\Enums\OfferStatus;
use App\Models\Offer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules an offer body must satisfy, shared by create and update (T-042).
 *
 * They live together because both verbs have to enforce them against the SAME
 * final row: a PATCH that only sends `ends_at` still has to be checked against
 * the stored `starts_at`, or the 90-day cap is trivially escaped in two
 * requests. {@see effective()} is what makes that possible — every cross-field
 * rule reads the merged value, not the submitted one.
 *
 * Where the database and the business disagree, both are enforced at their own
 * altitude: Postgres CHECKs percent at 1–100 (arithmetic — a percentage over 100
 * is meaningless whoever writes it), while 06 §2.2's narrower 5–50 lives here
 * (policy — a campaign could widen it without a migration).
 */
abstract class OfferWriteRequest extends FormRequest
{
    /** 06 §2.2: "max 90 days per offer, renewable". */
    public const MAX_WINDOW_DAYS = 90;

    /** 06 §2.2 percent band. Narrower than the DB CHECK on purpose. */
    public const PERCENT_MIN = 5;

    public const PERCENT_MAX = 50;

    /**
     * A fixed discount is stored in MINOR units, so this is €10,000 — high
     * enough never to bind on a real offer, low enough that a slipped decimal
     * (5000000 for "€50") is rejected instead of accruing fees.
     */
    public const MAX_FIXED_AMOUNT = 1_000_000;

    public const MAX_FREE_ITEMS = 20;

    /**
     * Statuses an operator may set directly.
     *
     * `expired` is excluded because it is computed from the window, never
     * asserted; `archived` because that is what DELETE means, and letting a
     * PATCH reach the terminal state would give the same act two spellings.
     *
     * @return list<string>
     */
    public static function writableStatuses(): array
    {
        return [OfferStatus::Draft->value, OfferStatus::Active->value, OfferStatus::Paused->value];
    }

    /**
     * The body rules, with the required-ness of the core fields switched by the
     * verb: create demands them, PATCH treats every field as optional.
     *
     * @return array<string, mixed>
     */
    protected function bodyRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'discount_type' => [$required, Rule::enum(OfferDiscountType::class)],
            'discount_value' => [$required, 'integer', 'min:1'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'starts_at' => [$required, 'date'],
            'ends_at' => ['nullable', 'date'],
            'quota_total' => ['nullable', 'integer', 'between:1,1000000'],
            'quota_per_user' => ['sometimes', 'integer', 'between:1,100'],
            'quota_per_day' => ['nullable', 'integer', 'between:1,100000'],
            'status' => ['sometimes', Rule::in(self::writableStatuses())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Only meaningful once the individual fields are well-formed;
            // otherwise "5–50" would be reported about a value that is not a
            // number at all.
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->validateDiscount($v);
            $this->validateWindow($v);
        });
    }

    /**
     * `discount_value` is one column in three units, so its bounds can only be
     * checked once the type is known — which is why this is here and not a rule.
     */
    private function validateDiscount(Validator $v): void
    {
        $type = $this->effectiveDiscountType();
        $value = $this->effective('discount_value');

        if ($type === null || ! is_numeric($value)) {
            return;
        }

        $value = (int) $value;

        match ($type) {
            OfferDiscountType::Percent => ($value < self::PERCENT_MIN || $value > self::PERCENT_MAX)
                ? $v->errors()->add('discount_value', 'A percentage discount must be between '.self::PERCENT_MIN.'% and '.self::PERCENT_MAX.'%.')
                : null,
            OfferDiscountType::FixedAmount => $value > self::MAX_FIXED_AMOUNT
                ? $v->errors()->add('discount_value', 'A fixed discount is given in minor units and may not exceed '.self::MAX_FIXED_AMOUNT.'.')
                : null,
            OfferDiscountType::FreeItem => $value > self::MAX_FREE_ITEMS
                ? $v->errors()->add('discount_value', 'A free-item offer may not exceed '.self::MAX_FREE_ITEMS.' items.')
                : null,
        };
    }

    /**
     * The window must be forward-going and at most 90 days.
     *
     * An omitted `ends_at` is not rejected — it is DEFAULTED (see
     * {@see resolvedEndsAt()}), so an operator who does not want to think about
     * an end date gets the maximum allowed run rather than an error or an
     * open-ended offer that quietly escapes the cap.
     */
    private function validateWindow(Validator $v): void
    {
        $startsAt = $this->effectiveDate('starts_at');
        if ($startsAt === null) {
            return;
        }

        $endsAt = $this->effectiveDate('ends_at');
        if ($endsAt === null) {
            return;
        }

        if (! $endsAt->isAfter($startsAt)) {
            $v->errors()->add('ends_at', 'The offer must end after it starts.');

            return;
        }

        if ($startsAt->diffInDays($endsAt) > self::MAX_WINDOW_DAYS) {
            $v->errors()->add('ends_at', 'An offer may run for at most '.self::MAX_WINDOW_DAYS.' days.');
        }
    }

    /**
     * `ends_at` as it should be STORED: what was sent, or 90 days after the
     * start when the client left it out.
     *
     * Returns null only when there is no start to measure from, which the rules
     * above already rejected on create.
     */
    public function resolvedEndsAt(): ?Carbon
    {
        $endsAt = $this->effectiveDate('ends_at');
        if ($endsAt !== null) {
            return $endsAt;
        }

        return $this->effectiveDate('starts_at')?->copy()->addDays(self::MAX_WINDOW_DAYS);
    }

    /**
     * The value this field will HAVE after the write: the submitted one when the
     * key is present, otherwise the stored one. On create there is no stored
     * row, so it is simply the input.
     */
    protected function effective(string $key): mixed
    {
        if ($this->has($key)) {
            return $this->input($key);
        }

        return $this->currentOffer()?->getAttribute($key);
    }

    protected function effectiveDate(string $key): ?Carbon
    {
        $value = $this->effective($key);

        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }

        // Unparseable input was already rejected by the `date` rule; this only
        // runs after that passed, so a failure here cannot be reached from the
        // request body — but a stored value is trusted, not re-parsed blindly.
        return Carbon::parse((string) $value);
    }

    private function effectiveDiscountType(): ?OfferDiscountType
    {
        $value = $this->effective('discount_type');

        if ($value instanceof OfferDiscountType) {
            return $value;
        }

        return is_string($value) ? OfferDiscountType::tryFrom($value) : null;
    }

    /** The row being updated, or null when creating. */
    protected function currentOffer(): ?Offer
    {
        $offer = $this->route('offer');

        return $offer instanceof Offer ? $offer : null;
    }
}
