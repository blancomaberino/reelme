<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'stripe_transfer_id' => null,
            'amount' => 2500,
            'currency' => 'EUR',
            'status' => PayoutStatus::Pending,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'idempotency_key' => null,
            'failure_reason' => null,
            'paid_at' => null,
        ];
    }

    public function processing(string $transferId = 'tr_test123'): static
    {
        return $this->state(fn () => [
            'status' => PayoutStatus::Processing,
            'stripe_transfer_id' => $transferId,
        ]);
    }

    public function paid(string $transferId = 'tr_testpaid'): static
    {
        return $this->state(fn () => [
            'status' => PayoutStatus::Paid,
            'stripe_transfer_id' => $transferId,
            'paid_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Insufficient funds'): static
    {
        return $this->state(fn () => [
            'status' => PayoutStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
