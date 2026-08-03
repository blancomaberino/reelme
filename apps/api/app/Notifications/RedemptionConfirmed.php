<?php

namespace App\Notifications;

use App\Models\Place;
use App\Models\Redemption;
use App\Notifications\Channels\ExpoChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Your code was accepted (T-043, 06 §3 sequence).
 *
 * Sent to the DINER the moment staff verify. It is the receipt: the app screen
 * that showed a live code is now stale, and without this the diner is left
 * looking at a QR that no longer works with no explanation. Dual-channel like
 * every other notification since T-040 — the database row backs the center, the
 * push reaches someone who has already put their phone away.
 */
class RedemptionConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Redemption $redemption)
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
     * @return array{type: string, url: string, title: string, body: string, redemption_id: string}
     */
    private function payload(): array
    {
        // `instanceof`, not `?->`: this runs in a queued worker after
        // SerializesModels re-resolves the relations, so a row that vanished
        // between dispatch and delivery must degrade to the fallback rather
        // than fail the job on every retry. (A nullsafe chain would be the
        // obvious spelling, but the FK chain makes `place` non-null to static
        // analysis, which then flags it as unnecessary.)
        $place = $this->redemption->offer?->place;
        $placeName = $place instanceof Place ? $place->name : 'the restaurant';

        return [
            'type' => 'redemption.verified',
            'url' => '/redemptions/'.$this->redemption->id,
            'title' => 'Offer redeemed',
            'body' => 'Your offer was redeemed at '.$placeName.'. Enjoy!',
            'redemption_id' => (string) $this->redemption->id,
        ];
    }
}
