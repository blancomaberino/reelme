<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The signup email-confirmation code (T-066). Queued on the `notifications`
 * queue (same as NewFollower); Mailpit captures it in local dev. Spanish copy —
 * Reelmap's default product language.
 */
class VerifyEmailCode extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly User $user, public readonly string $code)
    {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código de confirmación de Reelmap');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.verify-email-code',
            with: ['code' => $this->code, 'name' => $this->user->name],
        );
    }
}
