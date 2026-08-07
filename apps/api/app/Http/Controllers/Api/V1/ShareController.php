<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Exceptions\QuotaExhausted;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShareRequest;
use App\Http\Requests\UpdateShareRequest;
use App\Http\Resources\ShareResource;
use App\Jobs\IngestShare;
use App\Jobs\Pipeline;
use App\Models\HiddenPlace;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Ingestion\SourcePostResolver;
use App\Services\Places\ExtractionCorrector;
use App\Services\Places\PublishBestGuess;
use App\Services\Places\ResolvePendingPlace;
use App\Services\Quotas\QuotaSnapshot;
use App\Support\Contracts\ExtractionSchema;
use App\Support\KeysetPage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
        private readonly SourcePostResolver $sources,
        private readonly ExtractionCorrector $corrector,
        private readonly PublishBestGuess $bestGuess,
        private readonly QuotaSnapshot $quotas,
    ) {}

    public function store(StoreShareRequest $request): JsonResponse
    {
        $user = $request->user();

        // The daily cap, enforced against the SAME snapshot `GET /me` reported
        // and the app rendered — see the `shares` limiter for why this is not a
        // `Limit::perDay`.
        //
        // A typed exception rather than `abort(429)`, which renders as
        // `rate_limited` — the same code the 10/min BURST limiter produces. The
        // two want opposite advice ("wait a moment" vs "come back tomorrow"),
        // and a client branching on the status alone tells somebody who tapped
        // twice quickly that they are out for the day.
        if ($this->quotas->sharesExhausted($user)) {
            throw QuotaExhausted::shares(
                (int) config('quotas.daily.shares'),
                $this->quotas->resetsAt(),
            );
        }

        $url = $this->extractUrl($request);
        $caption = $request->string('caption')->value() ?: null;

        $source = $this->sources->resolve($url, $request->string('source_hint')->value() ?: null, $caption);

        // Duplicate guard: one share per (user, source_post). Never a 2nd row.
        // Fast path avoids the insert; the unique(user_id, source_post_id)
        // constraint + catch below closes the TOCTOU race two concurrent shares
        // of the same post would otherwise hit (common on a mobile double-tap /
        // share-sheet double-fire) — the loser returns the idempotent replay
        // instead of a 500.
        $existing = Share::where('user_id', $user->id)->where('source_post_id', $source->post->id)->first();
        if ($existing !== null) {
            // Re-sharing a post you'd soft-hidden is the natural "re-add" gesture
            // (there is no separate un-hide) — clear the dismissal so its pin
            // returns to your map + "my places" (T-071).
            $this->undismiss($existing);

            return $this->created($existing, $url, $source->platform, idempotentReplay: true);
        }

        try {
            // Wrap the insert in its own transaction/savepoint: on Postgres a
            // unique violation aborts the enclosing transaction, which would
            // poison the recovery SELECT below. The savepoint rolls back just the
            // failed insert, keeping the connection usable in the catch — correct
            // whether or not an ambient transaction is open.
            $share = DB::transaction(fn (): Share => Share::query()->forceCreate([
                'user_id' => $user->id,
                'source_post_id' => $source->post->id,
                'status' => ShareStatus::Pending->value,
                'shared_via' => $request->string('shared_via')->value()
                    ?: ($url !== null ? 'share_sheet' : 'manual'),
            ]));
        } catch (UniqueConstraintViolationException) {
            $winner = Share::where('user_id', $user->id)->where('source_post_id', $source->post->id)->firstOrFail();
            $this->undismiss($winner);

            return $this->created($winner, $url, $source->platform, idempotentReplay: true);
        }

        IngestShare::dispatch($share->id);

        return $this->created($share, $url, $source->platform, idempotentReplay: false);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Share::with(self::relations())
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id');

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        $paginator = $query->cursorPaginate((int) min(max((int) $request->integer('limit', 25), 1), 100));

        // The only endpoint on Laravel's own cursor paginator rather than
        // KeysetCursor — and so the only one with a prev_cursor.
        $page = KeysetPage::of(
            $paginator->items(),
            $paginator->perPage(),
            $paginator->nextCursor()?->encode(),
            $paginator->previousCursor()?->encode(),
        );

        return ApiResponse::page(ShareResource::collection($page->items), $page);
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

        return ApiResponse::item(['ok' => true]);
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
