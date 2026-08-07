<?php

namespace App\Jobs\Gdpr;

use App\Models\User;
use App\Notifications\DataExportReady;
use App\Services\Gdpr\UserDataExporter;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Build a user's data archive and send them a link (T-050, NFR-10).
 *
 * Queued rather than served inline because the collection touches a dozen
 * tables and the zip has to be written and uploaded — a request that did it
 * synchronously would time out for exactly the users who have the most data.
 *
 * Unique per user: hammering the export button should produce one archive, not
 * one per tap.
 */
class ExportUserData implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $userId)
    {
        $this->onQueue((string) config('gdpr.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(UserDataExporter $exporter): void
    {
        // Not withTrashed(): an account whose deletion is pending should not be
        // handed a fresh dossier of the data we are about to erase.
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $path = $exporter->export($user);

        // The URL is NOT passed in: this notification is itself queued, and a
        // live signed link on the object would be written into the jobs payload
        // (and failed_jobs). It mints its own inside toMail().
        $user->notify(new DataExportReady($path));

        // user_id only. The path embeds the unguessable ULID that is the
        // archive's whole protection, and an application log outlives both the
        // 24h signature and the 7-day retention.
        Log::info('gdpr.export.completed', ['user_id' => $user->id]);
    }
}
