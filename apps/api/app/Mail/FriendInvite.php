<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Join me on Reelmap" invite email (T-069). Queued on the notifications queue;
 * Spanish copy (the product's default language). Carries only the inviter's
 * display name and a public install/landing URL — no recipient data beyond the
 * address it's sent to.
 */
class FriendInvite extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $inviterName, public readonly string $inviteUrl)
    {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviterName} te invita a Reelmap");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.friend-invite',
            with: ['inviterName' => $this->inviterName, 'inviteUrl' => $this->inviteUrl],
        );
    }
}
