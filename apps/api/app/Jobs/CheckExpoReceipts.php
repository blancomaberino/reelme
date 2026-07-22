<?php

namespace App\Jobs;

use App\Models\Device;
use App\Services\Push\ExpoPushClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deferred half of Expo's two-phase delivery (T-027): the send returns a ticket
 * id, and the actual delivery outcome is available minutes later as a receipt.
 * We poll the receipts and prune any token whose receipt says
 * `DeviceNotRegistered` (app uninstalled / token invalidated) so the sender
 * doesn't accumulate dead tokens and get throttled by Expo.
 */
class CheckExpoReceipts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array<string, string>  $ticketTokens  ticketId => expo_push_token
     */
    public function __construct(private readonly array $ticketTokens)
    {
        $this->onQueue('notifications');
    }

    public function handle(ExpoPushClient $client): void
    {
        if ($this->ticketTokens === []) {
            return;
        }

        $receipts = $client->receipts(array_keys($this->ticketTokens));

        $dead = [];
        foreach ($receipts as $ticketId => $receipt) {
            if (isset($this->ticketTokens[$ticketId]) && ExpoPushClient::isDeviceNotRegistered($receipt)) {
                $dead[] = $this->ticketTokens[$ticketId];
            }
        }

        if ($dead !== []) {
            Device::query()->whereIn('expo_push_token', $dead)->delete();
        }
    }
}
