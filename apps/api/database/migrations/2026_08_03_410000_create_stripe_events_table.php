<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Stripe webhook we have seen (T-045, 03 §4.1).
 *
 * The `stripe_event_id` unique index IS the idempotency mechanism. Stripe
 * redelivers events — on our 5xx, on a timeout, sometimes just because — and
 * every handler here moves money or marks a payout paid. The row is inserted
 * BEFORE any side effect, so a redelivery loses to the index and stops before
 * it can act twice.
 *
 * The full payload is kept because reconciling a disputed payout six weeks later
 * means reading what Stripe actually said, not what our handler decided to
 * record about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type', 64);
            $table->jsonb('payload');
            // Null until a handler finishes: a row that exists with no
            // `processed_at` is one that arrived and failed mid-flight, which is
            // exactly what an admin needs to find after an incident.
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
