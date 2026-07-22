<?php

namespace App\Notifications\Channels;

use App\Jobs\CheckExpoReceipts;
use App\Models\Device;
use App\Services\Push\ExpoPushClient;
use Illuminate\Notifications\Notification;

/**
 * Delivers a notification's {@see ExpoMessage} payload to every one of the
 * recipient's registered Expo tokens (T-027, 05 §5). Two-phase dead-token
 * pruning: a `DeviceNotRegistered` that comes back synchronously in the send
 * *ticket* is pruned immediately; the delivery *receipt* for accepted messages
 * is polled later ({@see CheckExpoReceipts}) since Expo surfaces most
 * DeviceNotRegistered errors only there.
 */
class ExpoChannel
{
    public function __construct(private readonly ExpoPushClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $notification->toExpo($notifiable); // @phpstan-ignore-line method.notFound

        /** @var list<string> $tokens */
        $tokens = $notifiable->routeNotificationFor('expo', $notification);
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));

        if ($tokens === []) {
            return;
        }

        $messages = array_map(
            static fn (string $token): array => ['to' => $token] + $payload,
            $tokens,
        );

        $tickets = $this->client->send($messages);

        $accepted = []; // ticketId => token, for the deferred receipt sweep
        $dead = [];     // tokens Expo rejected outright

        foreach ($tickets as $i => $ticket) {
            $token = $tokens[$i] ?? null;
            if ($token === null) {
                continue;
            }

            if (($ticket['status'] ?? null) === 'ok' && is_string($ticket['id'] ?? null)) {
                $accepted[$ticket['id']] = $token;
            } elseif (ExpoPushClient::isDeviceNotRegistered($ticket)) {
                $dead[] = $token;
            }
        }

        if ($dead !== []) {
            Device::query()->whereIn('expo_push_token', $dead)->delete();
        }

        if ($accepted !== []) {
            CheckExpoReceipts::dispatch($accepted)
                ->delay(now()->addMinutes((int) config('services.expo.receipt_delay_minutes', 15)));
        }
    }
}
