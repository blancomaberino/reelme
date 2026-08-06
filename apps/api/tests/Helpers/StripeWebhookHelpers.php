<?php

/*
|--------------------------------------------------------------------------
| Shared Stripe-webhook test helpers
|--------------------------------------------------------------------------
| Used by the Stripe webhook suite and by the M4 loop test. Loaded from
| Pest.php for the same reason as the contract helper: a function declared
| inside a test FILE only exists once that file has been compiled, so a suite
| run on its own (`pest path/to/OneFile.php`) would fatal on a helper that
| happens to live in a sibling.
|
| These sign payloads the way Stripe really does rather than mocking
| `Webhook::constructEvent` — the point of the webhook tests is that OUR
| verification works, and a mock would only assert that we called something.
*/

use Illuminate\Testing\TestResponse;

const WEBHOOK_SECRET = 'whsec_test_secret_for_signing';

/** Sign a payload the way Stripe does, so the endpoint's real verifier runs. */
function stripeSignature(string $payload, ?int $timestamp = null, string $secret = WEBHOOK_SECRET): string
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return "t={$timestamp},v1={$signature}";
}

/**
 * @param  array<string, mixed>  $object
 */
function stripeEventPayload(string $type, array $object, string $id = 'evt_test_1'): string
{
    return json_encode([
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'created' => time(),
        'data' => ['object' => $object],
    ], JSON_THROW_ON_ERROR);
}

/**
 * POST a signed event at the real endpoint.
 *
 * The signature defaults to a VALID one so a caller testing something else
 * (the M4 loop) does not have to think about signing; the webhook suite passes
 * its own to exercise the rejection paths.
 *
 * @param  array<string, mixed>  $object
 */
function postWebhook(string $type, array $object, string $id = 'evt_test_1', ?string $signature = null): TestResponse
{
    $payload = stripeEventPayload($type, $object, $id);

    return test()->call(
        'POST',
        '/api/v1/webhooks/stripe',
        [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $signature ?? stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );
}
