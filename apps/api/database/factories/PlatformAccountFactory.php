<?php

namespace Database\Factories;

use App\Enums\Platform;
use App\Models\PlatformAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformAccount>
 */
class PlatformAccountFactory extends Factory
{
    protected $model = PlatformAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'platform' => Platform::Instagram,
            'external_user_id' => (string) fake()->unique()->numerify('178414###########'),
            'handle' => Str::lower(fake()->unique()->userName()),
            'access_token' => 'tok_'.Str::random(32),
            'refresh_token' => null,
            'token_expires_at' => now()->addDays(60),
            'scopes' => ['instagram_business_basic'],
            'last_synced_at' => now(),
        ];
    }

    public function instagram(): static
    {
        return $this->state(fn () => ['platform' => Platform::Instagram]);
    }

    /** A linked account whose token has already lapsed (treated as "no token"). */
    public function expired(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subDay()]);
    }
}
