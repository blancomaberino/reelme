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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
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
        $existing = Share::where('user_id', $user->id)->where('source_post_id', $post->id)->first();
        if ($existing !== null) {
            return $this->created($existing, $url, $platform, idempotentReplay: true);
        }

        $share = Share::query()->forceCreate([
            'user_id' => $user->id,
            'source_post_id' => $post->id,
            'status' => ShareStatus::Pending->value,
            'shared_via' => $request->string('shared_via')->value()
                ?: ($url !== null ? 'share_sheet' : 'manual'),
        ]);

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
        $share->load(self::RELATIONS);

        return response()->json([
            'data' => new ShareResource($share),
            'meta' => ['poll_interval_ms' => $this->pollInterval($share->status)],
        ]);
    }

    public function retry(Request $request, Share $share): JsonResponse
    {
        $this->authorize('update', $share);

        $retryable = $share->status === ShareStatus::Failed
            || ($share->status === ShareStatus::Review && $share->failure_reason === 'fetch_unavailable');

        abort_unless($retryable, 409, 'This share cannot be retried from its current state.');

        $last = $share->stageMetrics()->orderByDesc('id')->first();
        $stage = $last !== null && array_key_exists($last->stage, Pipeline::STAGES) ? $last->stage : 'fetch';

        $share->transitionTo(Pipeline::entryStatus($stage));
        Bus::chain(Pipeline::chain($share->id, $stage))->dispatch();

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
            $platform = $canonical->platform ?? $hintPlatform ?? Platform::Instagram;
            $externalId = $canonical->externalId ?? substr(sha1($canonical->url), 0, 40);

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
        $meta = ['poll_interval_ms' => 2000];
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
