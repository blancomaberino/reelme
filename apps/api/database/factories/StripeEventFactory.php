<?php

namespace Database\Factories;

use App\Models\StripeEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StripeEvent>
 */
class StripeEventFactory extends Factory
{
    protected $model = StripeEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = 'evt_'.Str::random(24);

        return [
            'stripe_event_id' => $id,
            'type' => 'account.updated',
            'payload' => ['id' => $id, 'type' => 'account.updated', 'data' => ['object' => []]],
            'processed_at' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => ['processed_at' => now()]);
    }
}
