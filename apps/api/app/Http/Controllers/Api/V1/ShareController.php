<?php

namespace App\Http\Controllers\Api\V1;

use App\Adapters\AdapterRegistry;
use App\Enums\FetchStatus;
use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShareRequest;
use App\Http\Requests\UpdateShareRequest;
use App\Http\Resources\ShareResource;
use App\Jobs\IngestShare;
use App\Jobs\Pipeline;
use App\Models\HiddenPlace;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Services\Ingestion\UrlCanonicalizer;
use App\Services\Places\ExtractionCorrector;
use App\Services\Places\PublishBestGuess;
use App\Services\Places\ResolvePendingPlace;
use App\Support\Contracts\ExtractionSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShareController extends Controller
{
    /**
     * Eager-loads for a share response. The nested `place` selects its lat/lng
     * inline (same expression as MapViewport / PlaceSummaryResource) so
     * ShareResource reads hydrated coordinates instead of firing a per-place
     * `Place::coordinates()` point query — the GET /shares N+1 (T-086).
     *
     * @return array<int|string, string|callable>
     */
    private static function relations(): array
    {
        $withCoords = fn ($query) => $query
            ->select('places.*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng');

        return [
            'sourcePost.influencer',
            'analysisRuns',
            'stageMetrics',
            'publishedPlaceSource.place' => $withCoords,
            'publishedPlaceSources.place' => $withCoords,
        ];
    }

    public function __construct(
        private readonly UrlCanonicalizer $canonicalizer,
        private readonly AdapterRegistry $registry,
        private readonly ExtractionCorrector $corrector,
        private readonly PublishBestGuess $bestGuess,
    ) {}

    public function store(StoreShareRequest $request): JsonResponse
    {
        $user = $request->user();
        $url = $this->extractUrl($request);
        $caption = $request->string('caption')->value() ?: null;

        [$post, $platform] = $this->resolveSourcePost($url, $request->string('source_hint')->value() ?: null, $caption);

        // Duplicate guard: one share per (user, source_post). Never a 2nd row.
        // Fast path avoids the insert; the unique(user_id, source_post_id)
        // constraint + catch below closes the TOCTOU race two concurrent shares
        // of the same post would otherwise hit (common on a mobile double-tap /
        // share-sheet double-fire) — the loser returns the idempotent replay
        // instead of a 500.
        $existing = Share::where('user_id', $user->id)->where('source_post_id', $post->id)->first();
        if ($existing !== null) {
            // Re-sharing a post you'd soft-hidden is the natural "re-add" gesture
            // (there is no separate un-hide) — clear the dismissal so its pin
            // returns to your map + "my places" (T-071).
            $this->undismiss($existing);

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
            $this->undismiss($winner);

            return $this->created($winner, $url, $platform, idempotentReplay: true);
        }

        IngestShare::dispatch($share->id);

        return $this->created($share, $url, $platform, idempotentReplay: false);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Share::with(self::relations())
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

    /**
     * Skip the confirm step and publish the share's best guess (T-098). The place
     * goes live immediately and is flagged for admin cleanup — the sharer's intent
     * is "just add it, I don't want to revise". 409 when the review isn't
     * best-guessable (e.g. geocode_failed has no location to publish).
     */
    public function publishBestGuess(Request $request, Share $share): JsonResponse
    {
        $this->authorize('update', $share);

        abort_unless(
            $this->bestGuess->publish($share),
            409,
            'This share cannot be published as-is from its current state.',
        );

        return $this->respondWithShare($share->refresh());
    }

    /**
     * PATCH /shares/{id} — apply a reviewer's corrected extraction and optionally
     * confirm publication (04 §7). Owner-only, valid only while `review`.
     */
    public function update(UpdateShareRequest $request, Share $share): JsonResponse
    {
        $this->authorize('update', $share);

        abort_unless($share->status === ShareStatus::Review, 409, 'This share can only be corrected while in review.');

        // The merge/diff engine lives in ExtractionCorrector (T-097) so it's
        // unit-testable and reusable; the controller keeps validation + the
        // publish transition + response shaping.
        $original = $this->corrector->original($share);
        $merged = $this->corrector->applyCorrection(
            $share,
            is_array($extraction = $request->input('extraction')) ? $extraction : null,
            is_array($candidate = $request->input('place_candidate')) ? $candidate : null,
        );

        // The whole merged payload must satisfy the full schema (additionalProperties
        // is false) — merging onto the complete original keeps required keys intact.
        $result = ExtractionSchema::validate($merged);
        if (! $result->isValid()) {
            $errors = ExtractionSchema::errors($result);

            throw ValidationException::withMessages(
                $errors !== [] ? $errors : ['extraction' => ['The corrected extraction is invalid.']],
            );
        }

        $share->corrected_extraction_json = $merged;
        $share->save();

        $this->corrector->recordCorrections($share, $original, $merged);

        if ($request->input('action') === 'publish') {
            $share->user_confirmed = true;
            $share->save();

            // Only dispatch the resolve→publish chain if we actually won the guard.
            if ($share->transitionTo(ShareStatus::Analyzing)) {
                Bus::chain(Pipeline::chain($share->id, 'resolve'))->dispatch();
            }
        }

        return $this->respondWithShare($share);
    }

    /**
     * Resolve a still-pending venue on a (partially-)published multi-place share
     * (T-071): attach + publish the picked candidate place, then drop the pending
     * entry. Owner-only; the resolver validates the index + candidate.
     */
    public function resolvePending(Request $request, Share $share, int $index, ResolvePendingPlace $resolver): JsonResponse
    {
        $this->authorize('update', $share);

        $validated = $request->validate(['place_id' => ['required', 'integer', 'min:1']]);
        $resolver->resolve($share, $index, (int) $validated['place_id']);

        return $this->respondWithShare($share->fresh() ?? $share);
    }

    /** Dismiss a pending venue without publishing it (T-071). Owner-only. */
    public function dismissPending(Request $request, Share $share, int $index, ResolvePendingPlace $resolver): JsonResponse
    {
        $this->authorize('update', $share);

        $resolver->dismiss($share, $index);

        return $this->respondWithShare($share->fresh() ?? $share);
    }

    private function respondWithShare(Share $share): JsonResponse
    {
        $share->load(self::relations());

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
    private function resolveSourcePost(?string $url, ?string $hint, ?string $caption = null): array
    {
        $hintPlatform = $hint !== null ? Platform::tryFrom($hint) : null;

        // Manual text share: a pasted caption IS the content. Store it pre-fetched
        // (any URL is kept only as a reference) so FetchSourcePost no-ops and the
        // pipeline extracts from the caption directly — the fetch-free demo path.
        // NOTE: each submission mints a fresh external_id, so the (user,source_post)
        // dedup guard can't fire — a resubmitted caption creates a new run/pin. Fine
        // for the demo; ResolvePlace still dedups the resulting *place* by geo+name.
        if ($caption !== null) {
            $externalId = 'manual-'.Str::ulid();

            $post = SourcePost::forceCreate([
                'platform' => ($hintPlatform ?? Platform::Instagram)->value,
                'external_id' => $externalId,
                'url' => $url !== null ? mb_substr($url, 0, 2048) : "manual://{$externalId}",
                'caption' => $caption,
                'fetch_status' => FetchStatus::Fetched->value,
                'fetched_at' => now(),
            ]);

            return [$post, $hintPlatform];
        }

        if ($url !== null) {
            $canonical = $this->canonicalizer->canonicalize($url);
            // The `url` field is validated max:2048 to match source_posts.url, but a
            // URL pulled out of `shared_text` (max:5000) or a shortlink expansion can
            // exceed that — reject cleanly instead of letting Postgres 22001 → 500.
            abort_if(mb_strlen($canonical->url) > 2048, 422, 'The resolved URL is too long.');

            // Launch gate (T-014): reject a share from a recognised but disabled
            // source (Instagram-only at launch) with a clear message, instead of
            // silently parking it for manual upload. Same switch the adapter chain
            // reads — flip ingestion.platforms.<p>.enabled to open a source.
            if ($canonical->platform !== null && ! $this->registry->platformEnabled($canonical->platform)) {
                throw ValidationException::withMessages([
                    'url' => "Sharing from {$canonical->platform->label()} isn't available yet — only Instagram is supported right now.",
                ]);
            }
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

    /** Un-hide the places this re-shared post resolves to (T-071) — idempotent. */
    private function undismiss(Share $share): void
    {
        $placeIds = PlaceSource::where('share_id', $share->id)->pluck('place_id');
        if ($placeIds->isNotEmpty()) {
            HiddenPlace::where('user_id', $share->user_id)->whereIn('place_id', $placeIds)->delete();
        }
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
