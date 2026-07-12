<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a review write (T-059): rating 1–5, optional body run through the
 * basic spam/profanity door check (the real moderation is the report → hide
 * queue). Auth is enforced by the route group.
 */
class ReviewUpsertRequest extends FormRequest
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
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:'.(int) config('reviews.body_max_length')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $body = (string) ($this->input('body') ?? '');
            if ($body === '') {
                return;
            }

            $links = preg_match_all('#https?://#i', $body);
            if ($links !== false && $links > (int) config('reviews.max_links')) {
                $v->errors()->add('body', 'Too many links for a review.');
            }

            /** @var list<string> $blocklist */
            $blocklist = config('reviews.blocklist', []);
            foreach ($blocklist as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $body) === 1) {
                    $v->errors()->add('body', 'The review contains disallowed language.');

                    return;
                }
            }
        });
    }
}
