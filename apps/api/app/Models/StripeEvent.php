<?php

namespace App\Models;

use Database\Factories\StripeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A webhook Stripe delivered (T-045, 03 §4.1).
 *
 * Recorded BEFORE anything acts on it. Stripe redelivers — on our 5xx, on a
 * timeout, sometimes unprompted — and every handler here moves money, so the
 * unique `stripe_event_id` is what makes a second delivery a no-op rather than
 * a second payout.
 *
 * @property int $id
 * @property string $stripe_event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 */
class StripeEvent extends Model
{
    /** @use HasFactory<StripeEventFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['stripe_event_id', 'type', 'payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** The event object Stripe sent, unwrapped from the envelope. */
    /**
     * @return array<string, mixed>
     */
    public function object(): array
    {
        $object = $this->payload['data']['object'] ?? [];

        return is_array($object) ? $object : [];
    }
}
