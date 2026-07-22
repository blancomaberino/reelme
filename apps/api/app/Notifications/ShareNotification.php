<?php

namespace App\Notifications;

use App\Models\Share;
use App\Notifications\Channels\ExpoChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Shared plumbing for the three pipeline-outcome pushes (T-027): a share moving
 * into `published` / `review` / `failed` notifies its owner via the database
 * channel (M3 notification center reads it) and Expo push (deep-link recovery
 * for a user who left the app). Subclasses supply the copy, `data.type`, and the
 * in-app deep-link path — the payload shape (05 §5.2) stays uniform.
 */
abstract class ShareNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected readonly Share $share)
    {
        // Match NewFollower: route to `notifications` so a plain queue:work setup
        // (not just Horizon's default supervisor) still drains these.
        $this->onQueue('notifications');
    }

    /** `data.type` per 05 §5.2 (e.g. `share.published`). */
    abstract protected function type(): string;

    /** In-app deep-link path the tap handler passes straight to `router.push`. */
    abstract protected function url(): string;

    abstract protected function title(): string;

    abstract protected function body(): string;

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
        return [
            'type' => $this->type(),
            'url' => $this->url(),
            'share_id' => $this->share->id,
        ];
    }

    /**
     * Expo message payload. `data: { type, url }` is the whole routing contract —
     * the mobile tap handler is switch-free (05 §5.2).
     *
     * @return array<string, mixed>
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'sound' => 'default',
            'channelId' => 'default',
            'data' => [
                'type' => $this->type(),
                'url' => $this->url(),
                // The id travels as data so the client invalidates ['shares', id]
                // directly — including a `published` push, whose url is /place/…
                // and carries no share id to parse out.
                'share_id' => $this->share->id,
            ],
        ];
    }

    /**
     * The published place's name for place-named copy, or null before/without a
     * published place (review/failed shares, or an odd published-without-place).
     */
    protected function placeName(): ?string
    {
        $name = $this->share->publishedPlaceSource?->place?->name;

        return is_string($name) && $name !== '' ? $name : null;
    }
}
