<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // IDs are string-typed in JSON (03-api-design §1). ULID prefixing
            // (usr_…) can land with the contracts work (T-005); cast now so the
            // mobile client types don't churn.
            'id' => (string) $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'avatar_path' => $this->avatar_path,
            'bio' => $this->bio,
            'birthdate' => $this->birthdate?->toDateString(),
            // Age is derived so it never goes stale in storage.
            'age' => $this->birthdate?->age,
            'favorite_topics' => $this->favorite_topics ?? [],
            'favorite_foods' => $this->favorite_foods ?? [],
            'is_influencer' => $this->is_influencer,
            'is_restaurant_owner' => $this->is_restaurant_owner,
            'is_admin' => $this->is_admin,
            'is_public' => $this->is_public,
            'preferred_analysis_model' => $this->preferred_analysis_model,
            'stripe_connect_onboarded' => $this->stripe_connect_onboarded_at !== null,
            'email_verified_at' => $this->email_verified_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
