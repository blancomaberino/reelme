<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FetchStatus;
use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShareRequest;
use App\Http\Resources\ShareResource;
use App\Jobs\IngestShare;
use App\Jobs\Pipeline;
use App\Models\Share;
use App\Models\SourcePost;
use App\Services\Ingestion\UrlCanonicalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShareController extends Controller
{
    private const RELATIONS = ['sourcePost.influencer', 'analysisRuns', 'stageMetrics'];

    public function __construct(private readonly UrlCanonicalizer $canonicalizer) {}

    public function store(StoreShareRequest $request): JsonResponse
    {
        $user = $request->user();
        $url = $this->extractUrl($request);

        [$post, $platform] = $this->resolveSourcePost($url, $request->string('source_hint')->value() ?: null);

        // Duplicate guard: one share per (user, source_post). Never a 2nd row.
        // Fast path avoids the insert; the unique(user_id, source_post_id)
        // constraint + catch below closes the TOCTOU race two concurrent shares
        // of the same post would otherwise hit (common on a mobile double-tap /
        // share-sheet double-fire) — the loser returns the idempotent replay
        // instead of a 500.
        $existing = Share::where('user_id', $user->id)->where('source_post_id', $post->id)->first();
        if ($existing !== null) {
            return $this->created($existing, $url, $platform, idempotentReplay: true);
        }

        try {
            // Wrap the insert in its own transaction/savepoint: on Postgres a
            // unique violation aborts the enclosing transaction, which would
            // poison the recovery SELECT below. The savepoint rolls back just the
            // failed insert, keeping the connection usable in the catch — correct
            // whether or not an ambient transaction is open.
            $share = DB::transaction(fn (): Share => Share::query()->forceCreate([
                'user_id' => $user->id,
                'source_post_id' => $post->id,
                'status' => ShareStatus::Pending->value,
                'shared_via' => $request->string('shared_via')->value()
                    ?: ($url !== null ? 'share_sheet' : 'manual'),
            ]));
        } catch (UniqueConstraintViolationException) {
            $winner = Share::where('user_id', $user->id)->where('source_post_id', $post->id)->firstOrFail();

            return $this->created($winner, $url, $platform, idempotentReplay: true);
        }

        IngestShare::dispatch($share->id);

        return $this->created($share, $url, $platform, idempotentReplay: false);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Share::with(self::RELATIONS)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id');

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        $page = $query->cursorPaginate((int) min(max((int) $request->integer('limit', 25), 1), 100));

        return response()->json([
            'data' => ShareResource::collection($page->items()),
            'meta' => ['pagination' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
                'limit' => $page->perPage(),
            ]],
        ]);
    }

    public function show(Request $request, Share $share): JsonResponse
    {
        $this->authorize('view', $share);

        return $this->respondWithShare($share);
    }

    public function retry(Request $request, Share $share): JsonResponse
    {
        $this->authorize('update', $share);

        $retryable = $share->status === ShareStatus::Failed
            || ($share->status === ShareStatus::Review && $share->failure_reason === 'fetch_unavailable');

        abort_unless($retryable, 409, 'This share cannot be retried from its current state.');

        $last = $share->stageMetrics()->orderByDesc('id')->first();
        $stage = $last !== null && array_key_exists($last->stage, Pipeline::STAGES) ? $last->stage : 'fetch';

        // Only dispatch if we actually won the transition. transitionTo() uses an
        // optimistic WHERE status = :expected guard and returns false when a
        // concurrent retry already moved the row — dispatching regardless would
        // enqueue a duplicate pipeline chain (double fetch/download/LLM cost).
        if (! $share->transitionTo(Pipeline::entryStatus($stage))) {
            abort(409, 'This share cannot be retried from its current state.');
        }

        Bus::chain(Pipeline::chain($share->id, $stage))->dispatch();

        return $this->respondWithShare($share);
    }

    private function respondWithShare(Share $share): JsonResponse
    {
        $share->load(self::RELATIONS);

        return response()->json([
            'data' => new ShareResource($share),
            'meta' => ['poll_interval_ms' => $this->pollInterval($share->status)],
        ]);
    }

    public function destroy(Request $request, Share $share): JsonResponse
    {
        $this->authorize('delete', $share);

        abort_if($share->status === ShareStatus::Published, 409, 'A published share cannot be discarded.');

        if (! $share->status->isTerminal()) {
            $share->transitionTo(ShareStatus::Rejected, 'user_discarded');
        }

        return response()->json(['data' => ['ok' => true], 'meta' => (object) []]);
    }

    /**
     * @return array{0: SourcePost, 1: ?Platform}
     */
    private function resolveSourcePost(?string $url, ?string $hint): array
    {
        $hintPlatform = $hint !== null ? Platform::tryFrom($hint) : null;

        if ($url !== null) {
            $canonical = $this->canonicalizer->canonicalize($url);
            // The `url` field is validated max:2048 to match source_posts.url, but a
            // URL pulled out of `shared_text` (max:5000) or a shortlink expansion can
            // exceed that — reject cleanly instead of letting Postgres 22001 → 500.
            abort_if(mb_strlen($canonical->url) > 2048, 422, 'The resolved URL is too long.');
            // NOTE: source_posts.platform is NOT NULL with 4 fixed values (02 §3.4),
            // but an unknown-host URL has no platform — a data-model gap. We store a
            // placeholder (hint or instagram) that FetchSourcePost ignores (it
            // re-resolves adapters by URL), and return the *real* (possibly null)
            // platform to the client. TODO(T-024/ADR): nullable platform / `unknown`.
            $platform = $canonical->platform ?? $hintPlatform ?? Platform::Instagram;
            $externalId = $canonical->externalId ?? sha1($canonical->url);

            $post = SourcePost::firstOrCreate(
                ['platform' => $platform, 'external_id' => $externalId],
                ['url' => $canonical->url],
            );

            return [$post, $canonical->platform];
        }

        // Pure manual share: no URL yet.
        $platform = $hintPlatform ?? Platform::Instagram;
        $externalId = 'manual-'.Str::ulid();

        $post = SourcePost::forceCreate([
            'platform' => $platform->value,
            'external_id' => $externalId,
            'url' => "manual://{$externalId}",
            'fetch_status' => FetchStatus::Manual->value,
        ]);

        return [$post, null];
    }

    private function extractUrl(Request $request): ?string
    {
        if ($url = $request->string('url')->value()) {
            return $url;
        }

        $text = $request->string('shared_text')->value();

        return preg_match('#https?://\S+#', $text, $m) === 1 ? $m[0] : null;
    }

    private function created(Share $share, ?string $url, ?Platform $platform, bool $idempotentReplay): JsonResponse
    {
        $meta = ['poll_interval_ms' => $this->pollInterval(ShareStatus::Pending)];
        if ($idempotentReplay) {
            $meta['idempotent_replay'] = true;
        }

        return response()->json([
            'data' => [
                'id' => (string) $share->id,
                'status' => $share->status->value,
                'url' => $url,
                'platform' => $platform?->value,
                'requires_manual_input' => $url === null || $platform === null,
                'place' => null,
                'created_at' => $share->created_at?->toIso8601ZuluString(),
            ],
            'meta' => $meta,
        ], 202);
    }

    private function pollInterval(ShareStatus $status): ?int
    {
        return match ($status) {
            ShareStatus::Pending, ShareStatus::Fetching, ShareStatus::Analyzing => 2000,
            ShareStatus::Review => 5000,
            default => null,
        };
    }
}
