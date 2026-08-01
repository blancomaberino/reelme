<?php

namespace App\Http\Resources;

use App\Enums\PlaceClaimMethod;
use App\Models\PlaceClaim;
use App\Services\Places\PlaceClaimService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The caller's own place claim (T-041) — drives the claim/resume flow.
 *
 * What is exposed from `evidence_json` is chosen one field at a time, never
 * spread: the hashed OTP and the attempt counter stay in the database. The
 * website token IS returned, because the claimant is the one who has to publish
 * it; the phone code is NOT, because the entire proof is that they can receive
 * it at the number we already hold.
 *
 * @mixin PlaceClaim
 */
class PlaceClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $evidence = $this->evidence_json ?? [];

        return [
            'id' => (string) $this->id,
            'place_id' => (string) $this->place_id,
            'status' => $this->status->value,
            'method' => $this->method->value,
            'reason' => $this->reason,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'expires_at' => $evidence['expires_at'] ?? null,

            // Website: the token and exactly where to put it, so the claimant
            // never has to guess the path.
            'token' => $this->when(
                $this->method === PlaceClaimMethod::Website,
                fn () => $evidence['token'] ?? null,
            ),
            'verification_url' => $this->when(
                $this->method === PlaceClaimMethod::Website && filled($this->place?->website),
                fn () => app(PlaceClaimService::class)
                    ->verificationUrl((string) $this->place->website),
            ),

            // Phone: only the last four digits of the number being called, so
            // the screen can say which line to answer without the API handing
            // back a number the caller may not already know.
            'phone_last4' => $this->when(
                $this->method === PlaceClaimMethod::Phone,
                fn () => $evidence['phone_last4'] ?? null,
            ),
        ];
    }
}
