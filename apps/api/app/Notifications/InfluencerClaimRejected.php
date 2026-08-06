<?php

namespace App\Notifications;

use App\Models\Influencer;
use App\Notifications\Channels\ExpoChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An admin rejected your claim over an influencer identity (T-038).
 *
 * Dual-channel like every other notification since T-040. It was database-only,
 * which made it the one outcome a user could never learn about without opening
 * the app and going looking — the worst candidate for silence, since a rejected
 * claim is precisely what someone is waiting on an answer for.
 */
class InfluencerClaimRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Influencer $influencer)
    {
        // Route explicitly so a plain queue:work setup can subscribe too.
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
     * @return array{type: string, url: string, title: string, body: string, influencer_handle: string, platform: string}
     */
    private function payload(): array
    {
        return [
            'type' => 'influencer.claim_rejected',
            // `/influencer/` SINGULAR — the Expo Router segment is
            // `app/influencer/[id]/index.tsx`. The plural spelling this used to
            // emit matched no route, so tapping the notification dead-ended on
            // the unmatched-route screen.
            'url' => '/influencer/'.$this->influencer->id,
            'title' => (string) __('notifications.influencer.claim_rejected.title'),
            'body' => (string) __('notifications.influencer.claim_rejected.body', [
                'handle' => $this->influencer->handle,
            ]),
            'influencer_handle' => $this->influencer->handle,
            'platform' => $this->influencer->platform->value,
        ];
    }
}
