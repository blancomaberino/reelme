<?php

namespace App\Services\Push;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin client over Expo's public push service (T-027) — send messages and, later,
 * fetch delivery receipts. Batched ≤100 per Expo's documented limit. Never
 * throws on a transport error: a push is best-effort recovery UX, not a
 * request-path dependency, so a failed batch yields empty tickets and the caller
 * moves on (the DB notification already landed for the notification center).
 */
class ExpoPushClient
{
    /** Expo's hard cap on messages/receipt-ids per request. */
    public const CHUNK = 100;

    /**
     * True when an Expo send-ticket or delivery-receipt reports the token is dead
     * (app uninstalled / token invalidated) → prune it. Guards `details` being a
     * non-array: Expo always sends an object, but a malformed payload must not
     * TypeError (the `?? null` only suppresses a *missing* key, not a scalar).
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function isDeviceNotRegistered(array $envelope): bool
    {
        if (($envelope['status'] ?? null) !== 'error') {
            return false;
        }

        $details = $envelope['details'] ?? null;

        return is_array($details) && ($details['error'] ?? null) === 'DeviceNotRegistered';
    }

    /**
     * POST message chunks to /push/send. Returns tickets aligned 1:1 with the
     * flattened input order — each `['status' => 'ok'|'error', 'id' => ?, ...]`.
     * A failed HTTP batch contributes an `error`/`transport` ticket per message
     * so callers keep positional alignment with their token list.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    public function send(array $messages): array
    {
        $tickets = [];

        foreach (array_chunk($messages, self::CHUNK) as $chunk) {
            $response = $this->request()->post('/send', $chunk);

            if (! $response->successful()) {
                foreach ($chunk as $_) {
                    $tickets[] = ['status' => 'error', 'details' => ['error' => 'transport']];
                }

                continue;
            }

            $data = $response->json('data');
            // Expo returns a `data` array positionally matching the request.
            if (! is_array($data) || count($data) !== count($chunk)) {
                foreach ($chunk as $_) {
                    $tickets[] = ['status' => 'error', 'details' => ['error' => 'transport']];
                }

                continue;
            }

            foreach ($data as $ticket) {
                $tickets[] = is_array($ticket)
                    ? $ticket
                    : ['status' => 'error', 'details' => ['error' => 'transport']];
            }
        }

        return $tickets;
    }

    /**
     * POST receipt ids to /push/getReceipts. Returns the `data` map of
     * `ticketId => ['status' => ..., 'details' => [...]]`. Empty on any failure.
     *
     * @param  list<string>  $ticketIds
     * @return array<string, array<string, mixed>>
     */
    public function receipts(array $ticketIds): array
    {
        $receipts = [];

        foreach (array_chunk($ticketIds, self::CHUNK) as $chunk) {
            $response = $this->request()->post('/getReceipts', ['ids' => $chunk]);

            if (! $response->successful()) {
                continue;
            }

            $data = $response->json('data');
            if (is_array($data)) {
                /** @var array<string, array<string, mixed>> $data */
                $receipts += $data;
            }
        }

        return $receipts;
    }

    private function request(): PendingRequest
    {
        $request = Http::asJson()
            ->acceptJson()
            ->baseUrl(rtrim((string) config('services.expo.base'), '/'))
            ->timeout((int) config('services.expo.timeout', 15));

        $token = config('services.expo.access_token');
        if (is_string($token) && $token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }
}
