<?php

namespace App\Jobs;

use App\Adapters\AdapterRegistry;
use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\NeedsManualFallback;
use App\Adapters\Exceptions\PostUnavailable;
use App\Enums\FetchStatus;
use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Jobs\Concerns\FailsShareOnError;
use App\Jobs\Concerns\RecordsStageMetrics;
use App\Models\Influencer;
use App\Models\PlatformAccount;
use App\Models\Share;
use App\Models\SourcePost;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Walks the adapter chain for the share's source_post URL; first successful
 * fetchMetadata() wins and persists caption/author/posted_at/oembed. Failures
 * advance the chain; NeedsManualFallback (chain exhausted) parks the share in
 * `review`. Idempotent: a source_post already `fetched` exits silently.
 */
class FetchSourcePost implements ShouldQueue
{
    use Batchable, Dispatchable, FailsShareOnError, InteractsWithQueue, Queueable, RecordsStageMetrics, SerializesModels;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 120;

    public function __construct(public readonly int $shareId)
    {
        $this->onQueue('ingest');
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["share:{$this->shareId}", 'stage:fetch'];
    }

    /**
     * Close this job's stage metric on success/failure (T-093). Only fires when
     * run through the queue worker; a direct ->handle() in a test bypasses it.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->stageMetricMiddleware();
    }

    public function handle(AdapterRegistry $registry): void
    {
        $share = Share::with('sourcePost')->find($this->shareId);

        if ($share === null || $share->status !== ShareStatus::Fetching) {
            return; // guard: not our turn
        }

        $post = $share->sourcePost;

        if ($post->fetch_status === FetchStatus::Fetched) {
            return; // idempotent: already fetched
        }

        $this->recordStage($share->id, 'fetch');

        // The sharer's linked platform account (T-015) authorizes the authed
        // Instagram strategy in the chain; null when unlinked/expired.
        $account = $this->linkedAccountFor($share->user_id, $post->platform);

        // Remember if a strategy reported "private, needs a linked account" so an
        // exhausted chain parks the share with `fetch_auth_required` (prompting
        // "link your account") rather than the generic `fetch_unavailable`.
        $authRequired = false;

        foreach ($registry->resolve($post->url) as $adapter) {
            try {
                $this->persist($post, $adapter->fetchMetadata($post->url, $account));

                return; // success — chain continues to DownloadMedia
            } catch (NeedsManualFallback) {
                // Chain exhausted → park for manual entry (not a failure).
                $share->transitionTo(ShareStatus::Review, $authRequired ? 'fetch_auth_required' : 'fetch_unavailable');

                return;
            } catch (FetchFailed $e) {
                if ($e->retryAfter !== null) {
                    $this->release($e->retryAfter); // back off (rate limit)

                    return;
                }
                // advance to the next adapter in the chain
            } catch (PostUnavailable $e) {
                $authRequired = $authRequired || $e->requiresAuth;
                // advance to the next adapter in the chain
            }
        }
    }

    /**
     * The sharer's linked platform account (T-015) mapped to the adapter DTO,
     * or null when unlinked/expired — the authed strategy in the chain uses it
     * to fetch a private post the sharer authorized. Expired tokens map to null
     * (via toLinkedAccount), so the public/manual path still runs.
     */
    private function linkedAccountFor(int $userId, Platform $platform): ?LinkedAccount
    {
        return PlatformAccount::query()
            ->where('user_id', $userId)
            ->where('platform', $platform)
            ->first()
            ?->toLinkedAccount();
    }

    private function persist(SourcePost $post, SourcePostData $data): void
    {
        $influencerId = null;
        if ($data->authorHandle !== null) {
            $influencerId = Influencer::firstOrCreate(
                ['platform' => $data->platform, 'handle' => ltrim($data->authorHandle, '@')],
                ['display_name' => $data->authorDisplayName],
            )->id;
        }

        $post->forceFill([
            'caption' => $data->caption,
            'influencer_id' => $influencerId,
            'posted_at' => $data->postedAt,
            'oembed_json' => $data->raw,
            'fetch_status' => FetchStatus::Fetched,
            'fetched_at' => now(),
        ])->save();
    }

    protected function failureCode(): string
    {
        return 'fetch_unavailable';
    }
}
