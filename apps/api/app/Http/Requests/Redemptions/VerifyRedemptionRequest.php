<?php

namespace App\Http\Requests\Redemptions;

use App\Models\Place;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/redemptions/verify` (T-043, 03 §2.13) — staff honour a code.
 *
 * Authorization is HERE rather than in the controller, for the same reason as
 * the offer update: everything downstream reads real redemption state, and a
 * caller who does not operate the venue must not reach any of it. A non-operator
 * gets 403 without learning whether the code they tried is real.
 *
 * The staff device's coordinates are optional. Location permission gets denied,
 * indoor GPS fails, and a restaurant that cannot serve a customer because a
 * phone could not get a fix is a worse outcome than an unverified location —
 * so a missing reading is recorded as unknown, not treated as a failure.
 */
class VerifyRedemptionRequest extends FormRequest
{
    private ?Place $place = null;

    public function authorize(): bool
    {
        $place = $this->place();

        return $place !== null && $this->user()?->ownsPlace($place) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Length-bounded, not shape-checked: a scanned QR payload is far
            // longer than a typed code, and both arrive in this one field.
            'code' => ['required', 'string', 'max:255'],
            'place_id' => ['required', 'integer', 'min:1'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /** The venue being verified at; null when absent or unknown. */
    public function place(): ?Place
    {
        if ($this->place !== null) {
            return $this->place;
        }

        $id = $this->input('place_id');

        if (! is_numeric($id)) {
            return null;
        }

        return $this->place = Place::query()->find((int) $id);
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }

    /**
     * Both coordinates or neither — a lone latitude is not a location, and
     * treating it as one would measure a distance from the prime meridian.
     *
     * @return array{lat: float|null, lng: float|null}
     */
    public function staffLocation(): array
    {
        $lat = $this->validated('lat');
        $lng = $this->validated('lng');

        if ($lat === null || $lng === null) {
            return ['lat' => null, 'lng' => null];
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }
}
