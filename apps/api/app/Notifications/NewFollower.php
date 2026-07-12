<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Someone followed you (T-037). Database channel now; the Expo push channel
 * joins in T-027 (devices) — the payload shape (05 §5.2) is already final.
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
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'social.follow',
            'follower_username' => $this->follower->username,
            'url' => '/users/'.$this->follower->username,
        ];
    }
}
