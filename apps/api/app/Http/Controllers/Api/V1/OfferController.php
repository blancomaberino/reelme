<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClaimStatus;
use App\Enums\OfferStatus;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Offers\OfferIndexRequest;
use App\Http\Requests\Offers\StoreOfferRequest;
use App\Http\Requests\Offers\UpdateOfferRequest;
use App\Http\Resources\OfferResource;
use App\Models\Builders\PlaceQueryBuilder;
use App\Models\Offer;
use App\Models\Place;
use App\Models\User;
use App\Support\KeysetCursor;
use App\Support\KeysetPage;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant offers (T-042, 03 §2.12, 06 §2.2).
 *
 * Routes are flat (`/offers`, not `/places/{id}/offers`) because the API spec is
 * canonical; the place travels in the body on create and is fixed thereafter.
 *
 * The index serves two audiences from one endpoint, switched by `?mine=1`:
 * without it, anyone sees only `active` offers (drafts and paused promos are the
 * operator's business); with it, an authenticated operator sees every offer for
 * every venue they hold a verified claim on, in every state. Keeping them one
 * endpoint means the diner-visible filter is defined once, and there is no
 * second listing route that could forget it.
 */
class OfferController extends Controller
{
    /** Cursor namespace — a cursor minted here is rejected by other endpoints. */
    private const CURSOR = 'offers';

    /**
     * The fields a write may set, in one place rather than once per verb.
     *
     * Create and update MUST allow exactly the same set — a column writable on
     * one and not the other is a rule that exists only in whichever verb the
     * next reader happens to open. `place_id` is absent on purpose: it comes
     * from the authorized place on create and can never change afterwards.
     *
     * @var list<string>
     */
    private const WRITABLE = [
        'title', 'description', 'discount_type', 'discount_value', 'terms',
        'starts_at', 'quota_total', 'quota_per_user', 'quota_per_day', 'status',
    ];

    /**
     * Diner browse + the operator's management list.
     *
     * `?active=1` narrows the public view further, to offers redeemable right
     * now: inside their validity window rather than merely marked active. That
     * distinction is the whole reason {@see Offer::scopeActive()} exists — an
     * offer that ended at 3am is still `status = active` in the column.
     */
    public function index(OfferIndexRequest $request): JsonResponse
    {
        $limit = $request->limit();

        // `lat`/`lng` are SQL aliases over the PostGIS column — the diner browse
        // renders these offers on a map (T-047), and without them the map
        // toggle has nothing to place. Selected on the RELATION so one query
        // still answers the whole list.
        $query = Offer::query()->with(['place' => fn ($q) => $q->selectRaw(
            'places.*, ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng'
        )]);

        if ($request->boolean('mine')) {
            // The route itself is public (diners browse it unauthenticated), so
            // the management view resolves the caller through the sanctum guard
            // and 401s on its own rather than gating the whole endpoint.
            $viewer = $request->user('sanctum');
            abort_unless($viewer instanceof User, 401);

            $query->whereIn('place_id', $this->operatedPlaceIds($viewer));
        } else {
            // Diners never see an offer whose venue is hidden, merged, or
            // tombstoned. Delegated to the place browse's own rule rather than
            // re-spelled here, so the two can never disagree about what is
            // visible.
            // The closure is annotated rather than typed: `whereHas()` declares
            // `Builder<Place>`, but Place::newEloquentBuilder() hands over a
            // PlaceQueryBuilder at runtime (ADR-106) — the same shape the other
            // call sites in the app use.
            $query->publiclyVisible()
                ->whereHas('place', function ($q): void {
                    /** @var PlaceQueryBuilder $q */
                    $q->publiclyVisible();
                });
        }

        // Applies to BOTH audiences: an operator filtering their list down to
        // "what a diner can redeem right now" means the same thing a diner does.
        if ($request->activeOnly()) {
            $query->active();
        }

        if (($placeId = $request->validated('place_id')) !== null) {
            $query->where('place_id', (int) $placeId);
        }

        if (($near = $request->nearPoint()) !== null) {
            $radius = $request->radiusM();
            $query->whereHas('place', function ($q) use ($near, $radius): void {
                /** @var PlaceQueryBuilder $q */
                $q->whereRaw(
                    'ST_DWithin(location, ST_MakePoint(?, ?)::geography, ?)',
                    [$near['lng'], $near['lat'], $radius],
                );
            });
        }

        // Newest first, id-keyed: offers are created one at a time by hand, so
        // the id order is the creation order and one key is enough.
        $cursor = KeysetCursor::decode($request->validated('cursor'), self::CURSOR, 1);
        $query->orderByDesc('id');
        if ($cursor !== null) {
            $query->where('id', '<', KeysetCursor::intKey($cursor[0]));
        }

        $page = KeysetPage::query($query, $limit, self::CURSOR, fn (Offer $last) => [$last->id]);

        return ApiResponse::page(OfferResource::collection($page->items), $page);
    }

    /**
     * Offer detail. Public for a published offer; a draft, paused, or archived
     * one is visible only to the venue's operator, and 404s (never 403) for
     * everyone else — the same no-oracle rule the owned-list routes use.
     */
    public function show(Request $request, Offer $offer): JsonResponse
    {
        $offer->load('place');

        if (! $this->isPubliclyVisible($offer)) {
            $viewer = $request->user('sanctum');
            abort_unless($viewer instanceof User && $viewer->can('view', $offer), 404);
        }

        return ApiResponse::item(new OfferResource($offer));
    }

    /**
     * Create an offer for a place the caller operates.
     *
     * Authorization happens in the FormRequest, which has to resolve the place
     * from the body before it can ask the policy — so by the time this runs, the
     * caller is a verified operator of a place that exists.
     */
    public function store(StoreOfferRequest $request): JsonResponse
    {
        /** @var Place $place */
        $place = $request->place();

        $offer = new Offer($request->safe()->only(self::WRITABLE));
        $offer->place_id = $place->id;
        $offer->created_by_user_id = (int) $this->requireUser($request)->id;
        // Defaulted rather than echoed: an omitted end date becomes the longest
        // run 06 §2.2 allows, so the cap holds without the operator having to
        // pick a date, and the API never mints an offer that outlives it.
        $offer->ends_at = $request->resolvedEndsAt();
        $offer->save();

        return ApiResponse::item(new OfferResource($offer->load('place')), [], 201);
    }

    /** Edit, pause (`status=paused`), or resume (`status=active`). */
    public function update(UpdateOfferRequest $request, Offer $offer): JsonResponse
    {
        $this->authorize('update', $offer);
        // An archived offer is terminal: redemptions and ledger entries point at
        // it, so its terms must stay exactly what the diner agreed to.
        abort_unless($offer->status->isEditable(), 409, 'An archived offer can no longer be edited.');

        $offer->fill($request->safe()->only(self::WRITABLE));

        // Only when the client actually touched the window: resolvedEndsAt()
        // would otherwise re-default a deliberately open-ended row on every
        // unrelated PATCH.
        if ($request->has('starts_at') || $request->has('ends_at')) {
            $offer->ends_at = $request->resolvedEndsAt();
        }

        $offer->save();

        return ApiResponse::item(new OfferResource($offer->load('place')));
    }

    /**
     * Archive — never a hard delete.
     *
     * Redemptions (T-043) and ledger entries (T-044) reference the offer, and a
     * fee charged against a row that no longer exists cannot be audited or
     * disputed. Idempotent: archiving an archived offer succeeds.
     */
    public function destroy(Request $request, Offer $offer): JsonResponse
    {
        $this->authorize('delete', $offer);

        $offer->status = OfferStatus::Archived;
        $offer->save();

        return ApiResponse::item(new OfferResource($offer->load('place')));
    }

    /**
     * Ids of every place the user holds a VERIFIED claim on — a subquery, not a
     * fetched list, so an operator with many venues costs one round trip.
     */
    private function operatedPlaceIds(User $user): QueryBuilder
    {
        return DB::table('place_claims')
            ->select('place_id')
            ->where('user_id', $user->id)
            ->where('status', ClaimStatus::Verified->value);
    }

    /** Is this offer on a diner-facing surface at all? */
    private function isPubliclyVisible(Offer $offer): bool
    {
        return $offer->status === OfferStatus::Active
            && $offer->place?->isPubliclyVisible() === true;
    }

    private function requireUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
