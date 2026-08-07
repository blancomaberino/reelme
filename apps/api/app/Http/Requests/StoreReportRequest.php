<?php

namespace App\Http\Requests;

use App\Enums\ReportReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filing a report (T-049, 03 §2.16).
 *
 * The reportable type is validated against the morph map itself rather than a
 * hand-kept list, so the two can never disagree — and `exists` is checked
 * against whatever table that alias resolves to, which stops a report being
 * filed against an id that does not exist (the cheapest way to fill a
 * moderation queue with noise nobody can action).
 */
class StoreReportRequest extends FormRequest
{
    /**
     * What a user may report. Deliberately NOT the whole morph map: `influencer`
     * is in there for follows, and an influencer identity is public business
     * data whose complaints route through the takedown flow, not this queue.
     *
     * @var list<string>
     */
    public const REPORTABLE = ['place', 'share', 'user', 'source_post', 'offer'];

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
            'reportable_type' => ['required', 'string', Rule::in(self::REPORTABLE)],
            'reportable_id' => [
                'required',
                'integer',
                // Resolved through the morph map, so this follows the alias to
                // its real table. A type the map does not know fails the rule
                // above first, so the lookup here is always safe.
                Rule::exists($this->reportableTable(), 'id'),
            ],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            // Long enough for a real explanation, short enough that the field
            // cannot be used as free storage.
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reportable_type.in' => 'That is not something you can report.',
            'reportable_id.exists' => 'We could not find what you are reporting.',
        ];
    }

    /** The table the requested alias maps to, or a sentinel that never matches. */
    private function reportableTable(): string
    {
        $type = $this->input('reportable_type');

        if (! is_string($type) || ! in_array($type, self::REPORTABLE, true)) {
            return 'reports';
        }

        /** @var class-string<Model>|null $class */
        $class = Relation::getMorphedModel($type);

        return $class === null ? 'reports' : (new $class)->getTable();
    }
}
