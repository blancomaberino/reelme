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
            // `url:http,https`, not a bare `url`: this is untrusted input into a
            // field the app and the admin panel both render as a link, and the
            // bare rule accepts any scheme — `javascript:` included. The mobile
            // client happens to gate on http(s) before opening one, but "the
            // current client guards it" is not a reason to store it.
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            // One rule per line, as the curator form stores them. Bounded so a
            // proposal stays something a moderator can read in one screen.
            //
            // `list`, not just `array` (T-128): the contract pins this column as
            // a FLAT LIST OF STRINGS, and `array` alone accepts
            // `opening_hours_json[monday]=9-5` — every element is still a string,
            // so the `.*` rule passes, and the value lands in `jsonb` as a JSON
            // OBJECT. An operator edit applies straight to the place, so that one
            // request is enough to serve a payload the client's `string[]` type
            // says is impossible.
            'opening_hours_json' => ['sometimes', 'nullable', 'array', 'list', 'max:14'],
            'opening_hours_json.*' => ['string', 'max:120'],
            // "Something else is wrong" (T-112) — not a column on `places`, so
            // it is validated here and read by `note()` rather than by `patch()`.
            'note' => ['sometimes', 'nullable', 'string', 'max:'.PlaceEditSuggestion::NOTE_MAX],
        ];
    }

    /**
     * The submitter's free-text note, or null when they left it blank.
     *
     * Trimmed to null HERE rather than in the service, because "  " and "" and
     * absent all have to mean the same thing before anything downstream decides
     * whether this submission carries anything at all — the empty-form refusal
     * turns on exactly that question.
     */
    public function note(): ?string
    {
        $note = trim((string) $this->safe()->input('note', ''));

        return $note === '' ? null : $note;
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
