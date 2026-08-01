<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Someone followed you (T-037). Dual-channel as of T-040: the database row
 * backs the notification center, the Expo push reaches a user who is not in the
 * app. Both carry the same `{type, url, title, body}` payload (05 §5.2), so a
 * tapped push and a tapped center row route through one path.
 */
class NewFollower extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly User $follower)
    {
        // Horizon's supervisor-default consumes ['default', 'notifications'];
        // route explicitly so a plain `queue:work` setup can subscribe too.
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
     * One payload, both channels — so the center and the push can never drift
     * into showing different copy for the same event.
     *
     * @return array{type: string, url: string, title: string, body: string, follower_username: string}
     */
    private function payload(): array
    {
        return [
            'type' => 'social.follow',
            'url' => '/users/'.$this->follower->username,
            'title' => 'New follower',
            'body' => '@'.$this->follower->username.' started following you.',
            'follower_username' => $this->follower->username,
        ];
    }
}
