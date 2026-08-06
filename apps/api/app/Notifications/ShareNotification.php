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
 * channel (the notification center reads it) and Expo push (deep-link recovery
 * for a user who left the app). Subclasses supply `data.type` and the in-app
 * deep-link path — the payload shape (05 §5.2) stays uniform.
 *
 * Copy is TRANSLATED, keyed off `data.type`: `share.published` reads
 * `notifications.share.published.{title,body}`. One string, no per-class copy,
 * and a new type cannot ship with copy in only one language without the
 * key-parity test noticing.
 *
 * Because the notification is queued and `User` implements
 * `HasLocalePreference`, Laravel has already switched the app locale to the
 * RECIPIENT's language by the time these run — so `__()` here composes in their
 * language, not the language of whoever triggered the event.
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

    /** `data.type` per 05 §5.2 (e.g. `share.published`) AND the lang key path. */
    abstract protected function type(): string;

    /** In-app deep-link path the tap handler passes straight to `router.push`. */
    abstract protected function url(): string;

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
            // Stored in the recipient's language at SEND time. The center
            // re-renders from `type` + the params below so it follows a later
            // language switch, but these stay the fallback for a row whose type
            // the client doesn't recognise (and for any older build).
            'title' => $this->title(),
            'body' => $this->body(),
            'share_id' => $this->share->id,
            ...$this->params(),
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
     * Structured values the CENTER interpolates into its own translation of this
     * notification. Anything a body string interpolates has to appear here, or
     * the client can only fall back to the frozen server copy.
     *
     * @return array<string, mixed>
     */
    protected function params(): array
    {
        return [];
    }

    protected function title(): string
    {
        return (string) __('notifications.'.$this->type().'.title');
    }

    protected function body(): string
    {
        return (string) __('notifications.'.$this->type().'.body');
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
