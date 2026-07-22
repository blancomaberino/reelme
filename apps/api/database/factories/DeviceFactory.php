<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Expo token shape: ExponentPushToken[<22 url-safe chars>].
            'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
            'platform' => fake()->randomElement(['ios', 'android']),
            'device_name' => fake()->randomElement(['iPhone 15', 'Pixel 8', null]),
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
        ];
    }

    public function ios(): static
    {
        return $this->state(fn () => ['platform' => 'ios']);
    }

    public function android(): static
    {
        return $this->state(fn () => ['platform' => 'android']);
    }
}
