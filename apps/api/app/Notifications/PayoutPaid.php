<?php

namespace App\Notifications;

use App\Models\Payout;
use App\Notifications\Channels\ExpoChannel;
use App\Services\Payments\PayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Stripe confirmed your payout moved (T-045).
 *
 * `wallet.payout` has been in the client's type union and icon map since T-040,
 * but nothing ever emitted it: a payout went `pending → processing → paid`
 * entirely silently, so the only way to learn that money was on its way was to
 * open the wallet and notice the balance had changed. Money leaving the platform
 * toward a user is the single event most worth interrupting them for.
 *
 * Sent from {@see PayoutService::markPaid()} — on the
 * `paid` transition only, never on `processing`, so it cannot promise money for
 * a transfer Stripe later fails.
 */
class PayoutPaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Payout $payout)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return ['database', ExpoChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function toExpo(object $notifiable): array
    {
        $payload = $this->payload();

        return [
            'title' => $payload['title'],
            'body' => $payload['body'],
            'sound' => 'default',
            'channelId' => 'default',
            'data' => ['type' => $payload['type'], 'url' => $payload['url']],
        ];
    }

    /**
     * @return array{type: string, url: string, title: string, body: string, payout_id: string, amount_minor: int, currency: string}
     */
    private function payload(): array
    {
        return [
            'type' => 'wallet.payout',
            'url' => '/wallet',
            'title' => (string) __('notifications.wallet.payout.title'),
            'body' => (string) __('notifications.wallet.payout.body', [
                'amount' => $this->formatAmount(),
            ]),
            'payout_id' => (string) $this->payout->id,
            // Minor units + code, NOT the formatted string: the center formats
            // money with the user's own currency setting, and re-parsing
            // "$ 1.234,00" back into a number to do that would be absurd.
            'amount_minor' => $this->payout->amount,
            'currency' => $this->payout->currency,
        ];
    }

    /**
     * Money for the PUSH only, where there is no client to format it.
     *
     * Mirrors the mobile `formatMoney()` exactly — same symbol table, same
     * `symbol + two decimals` shape. It has to: the banner and the notification
     * center row describe one payout, and "EUR 60,00" on the lock screen above
     * "€60.00" in the list reads like two different amounts.
     *
     * Deliberately not `Number::currency` / `intl`: the container's ICU data is
     * not pinned, so the same payout would render differently per host.
     */
    private function formatAmount(): string
    {
        $symbol = match (mb_strtoupper($this->payout->currency)) {
            'EUR' => '€',
            'GBP' => '£',
            default => '$',
        };

        return $symbol.number_format($this->payout->amount / 100, 2, '.', '');
    }
}
