<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ParsesNearPoint;
use App\Models\Dish;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the public place index query (T-030, 03 §2.6). `near` arrives as a
 * comma-joined `lat,lng`; it is split here into named, range-checked fields.
 * `sort=distance` is only meaningful relative to a point, so it requires `near`.
 */
class PlaceIndexRequest extends FormRequest
{
    use ParsesNearPoint;

    public const DEFAULT_RADIUS_M = 2000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeNearPoint();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:96'],
            'card' => ['nullable', 'string', 'max:64'],
            // "…that do pasta" (T-157). The minimum gives the caller a 422
            // instead of a silently empty page; the REAL floor is
            // `Dish::MIN_QUERY` on the normalized needle in
            // {@see PlaceQueryBuilder::servingDish()}, because this rule counts
            // raw characters and `?dish=p.` would clear it.
            'dish' => ['nullable', 'string', 'min:'.Dish::MIN_QUERY, 'max:'.Dish::MAX_NAME],
            ...$this->nearRules(),
            'radius_m' => ['nullable', 'integer', 'between:1,50000'],
            'influencer_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['recent', 'popular', 'distance'])],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->nearMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('sort') === 'distance' && ! is_string($this->query('near'))) {
                $v->errors()->add('sort', 'sort=distance requires the near parameter.');
            }
        });
    }

    public function radiusM(): int
    {
        return (int) ($this->validated('radius_m') ?? self::DEFAULT_RADIUS_M);
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'recent');
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 25);
    }
}
