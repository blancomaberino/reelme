<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your data export is ready" (T-050, NFR-10).
 *
 * The signed download link goes in the EMAIL and nowhere else. It is a bearer
 * URL to the densest collection of personal data we ever assemble, and the
 * in-app notification center keeps its rows for months — parking a link there
 * would leave a working handle on that archive sitting in a list long after the
 * person had forgotten they asked for it. (It would also read as broken: the
 * link expires in a day, the row does not.)
 *
 * So the push/database side says "it's ready, check your email" and routes to
 * the privacy screen, which is where a user goes to ask again.
 */
class DataExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $path,
        private readonly string $downloadUrl,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = (int) config('gdpr.export_url_ttl_hours');

        return (new MailMessage)
            ->subject(__('Your Reelmap data export is ready'))
            ->line(__('You asked for a copy of your Reelmap data. It is ready to download.'))
            ->action(__('Download my data'), $this->downloadUrl)
            ->line(__('This link expires in :hours hours. You can request a new export any time from Settings → Privacy & data.', ['hours' => $hours]))
            ->line(__('If you did not request this, please change your password — someone else may have access to your account.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'account.export_ready',
            'url' => '/settings/privacy',
            'title' => (string) __('notifications.account.export_ready.title'),
            'body' => (string) __('notifications.account.export_ready.body'),
            // The storage key, not a URL: useful to support when someone says
            // the mail never arrived, and harmless if the row leaks.
            'export_path' => $this->path,
        ];
    }
}
