<?php

namespace App\Http\Requests\Places;

use App\Models\PlaceEditSuggestion;
use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /places/{place}/suggestions — propose a correction (T-083).
 *
 * Every key is `sometimes`: a suggestion is a PATCH of the fields the submitter
 * touched, and "absent" has to stay distinguishable from "cleared". The two
 * columns the schema declares NOT NULL (`name`, `country_code`) are therefore
 * the only two that are not nullable here — a suggestion may correct them, never
 * empty them.
 *
 * The limits mirror the Filament curator form field for field, on purpose: the
 * same value has to be acceptable whether a curator types it or a diner suggests
 * it, and the column widths are the same either way.
 */
class SuggestPlaceEditRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any signed-in user may suggest; the route's auth middleware is the gate.
        // Whether it APPLIES or queues is decided by ownership, not by authz.
        return true;
    }

    /**
     * Normalize the country the same way the profile does (T-110), so "uy"
     * validates rather than being rejected for its case.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('country_code')) {
            $this->merge(['country_code' => Countries::normalize($this->input('country_code'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            // The bundled ISO allow-list rather than a loose `size:2` (T-110):
            // ICU renders an unknown code as itself, so "ZZ" would show up in
            // the app as a country nobody could explain.
            'country_code' => ['sometimes', 'string', Rule::in(Countries::CODES)],
            'cuisine_primary' => ['sometimes', 'nullable', 'string', 'max:120'],
            'price_range' => ['sometimes', 'nullable', 'integer', 'between:1,4'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website' => ['sometimes', 'nullable', 'url', 'max:2048'],
            // One rule per line, as the curator form stores them. Bounded so a
            // proposal stays something a moderator can read in one screen.
            'opening_hours_json' => ['sometimes', 'nullable', 'array', 'max:14'],
            'opening_hours_json.*' => ['string', 'max:120'],
        ];
    }

    /**
     * The proposed patch — only the allow-listed keys the request actually
     * carried.
     *
     * `only()` on the validated set rather than `validated()` wholesale: a rule
     * key like `opening_hours_json.*` comes back as its own entry, and passing
     * that through to the editor would try to write a column named `*`.
     *
     * @return array<string, mixed>
     */
    public function patch(): array
    {
        /** @var array<string, mixed> $patch */
        $patch = $this->safe()->only(PlaceEditSuggestion::FIELDS);

        return $patch;
    }
}
