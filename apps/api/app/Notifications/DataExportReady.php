<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoChannel;
use App\Services\Gdpr\UserDataExporter;
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
 * The URL is also NOT a constructor argument. This is a queued notification, so
 * anything held on the object is serialized into the `jobs` payload — and into
 * `failed_jobs`, indefinitely, on a failure. Minting it inside `toMail()` keeps
 * the live URL out of the queue entirely, and starts its 24h clock when the
 * mail is actually composed rather than when the job was enqueued.
 */
class DataExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $path)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        // Push too, like every sibling: an export takes minutes to build, and
        // without a banner the only way to learn it finished is to open the app
        // and look — or to notice the email, which is the one channel a user
        // asking about their privacy is least likely to be watching.
        return ['mail', 'database', ExpoChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = (int) config('gdpr.export_url_ttl_hours');

        return (new MailMessage)
            ->subject(__('notifications.account.export_ready.mail.subject'))
            ->line(__('notifications.account.export_ready.mail.intro'))
            ->action(
                __('notifications.account.export_ready.mail.cta'),
                app(UserDataExporter::class)->downloadUrl($this->path),
            )
            ->line(__('notifications.account.export_ready.mail.expiry', ['hours' => $hours]))
            ->line(__('notifications.account.export_ready.mail.warning'));
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
     * @return array{type: string, url: string, title: string, body: string, export_path: string}
     */
    private function payload(): array
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
