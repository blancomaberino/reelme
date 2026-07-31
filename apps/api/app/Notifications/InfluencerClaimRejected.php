<?php

namespace App\Notifications;

use App\Models\Influencer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * An admin rejected your claim over an influencer identity (T-038). Database
 * channel now; the Expo push channel joins via the shared notifications queue
 * (T-027). The payload shape mirrors the other social notifications (05 §5.2).
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
            'type' => 'influencer.claim_rejected',
            'url' => '/influencers/'.$this->influencer->id,
            // The center renders from these; see ShareNotification (T-040).
            'title' => 'Claim not approved',
            'body' => 'Your claim on @'.$this->influencer->handle.' was not approved.',
            'influencer_handle' => $this->influencer->handle,
            'platform' => $this->influencer->platform->value,
        ];
    }
}
