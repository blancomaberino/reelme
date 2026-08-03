<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Redemptions\IssueRedemptionRequest;
use App\Http\Requests\Redemptions\VerifyRedemptionRequest;
use App\Http\Resources\RedemptionResource;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Redemption;
use App\Models\User;
use App\Policies\RedemptionPolicy;
use App\Services\Redemptions\RedemptionIssuer;
use App\Services\Redemptions\RedemptionVerifier;
use App\Support\KeysetCursor;
use App\Support\KeysetPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Redemptions (T-043, 03 §2.13, 06 §3) — the payable event of the business.
 *
 * The controller is deliberately thin. Issuing runs a table of anti-fraud rules
 * and verification has to be exactly-once under concurrency; both live in
 * `Services\Redemptions`, where they can be tested without a request and where
 * T-044's ledger can hook the same transaction.
 *
 * Two audiences read these rows and they see different things: the diner gets
 * the CODE (they have to present it), the operator never does (they are handed
 * it at the till). {@see RedemptionResource::withCode()} is where that split is
 * expressed, and it defaults to withholding.
 */
class RedemptionController extends Controller
{
    private const CURSOR = 'redemptions';

    /** Diner claims a code for an offer. */
    public function store(IssueRedemptionRequest $request, RedemptionIssuer $issuer): JsonResponse
    {
        $offer = Offer::query()->with('place')->find($request->offerId());
        // An unknown offer and an unavailable one answer alike: offers are a
        // public surface, so this leaks nothing, and it keeps the client's error
        // handling to one branch.
        abort_if($offer === null, 422, 'This offer is not available right now.');

        $redemption = $issuer->issue($offer, $this->user($request), $request->referralShareId());

        return ApiResponse::item(
            (new RedemptionResource($redemption->load('offer')))->withCode(),
            [],
            201,
        );
    }

    /**
     * One redemption. The diner sees their code; the venue's operator sees the
     * row without it (both are authorized by {@see RedemptionPolicy}).
     */
    public function show(Request $request, Redemption $redemption): JsonResponse
    {
        $this->authorize('view', $redemption);

        $isOwner = (int) $redemption->user_id === (int) $this->user($request)->id;

        return ApiResponse::item(
            (new RedemptionResource($redemption->load('offer')))->withCode($isOwner),
        );
    }

    /** The diner's own history, newest first. */
    public function index(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->integer('limit', 25)));

        $query = Redemption::query()
            ->where('user_id', $this->user($request)->id)
            ->with('offer.place')
            ->orderByDesc('id');

        $cursor = KeysetCursor::decode($request->query('cursor'), self::CURSOR, 1);
        if ($cursor !== null) {
            $query->where('id', '<', KeysetCursor::intKey($cursor[0]));
        }

        $page = KeysetPage::query($query, $limit, self::CURSOR, fn (Redemption $last) => [$last->id]);

        // The history carries codes: these are the caller's OWN redemptions, and
        // a still-live one is exactly what they came back to the screen for.
        return ApiResponse::page(
            $page->items->map(fn (Redemption $r) => (new RedemptionResource($r))->withCode()),
            $page,
        );
    }

    /**
     * The venue's redemption log. Operator-only, and deliberately WITHOUT codes
     * — a log that listed live codes would be a list of free meals.
     */
    public function forPlace(Request $request, Place $place): JsonResponse
    {
        abort_unless($this->user($request)->ownsPlace($place), 403);

        $limit = min(50, max(1, (int) $request->integer('limit', 25)));

        $query = Redemption::query()
            ->whereIn('offer_id', Offer::query()->select('id')->where('place_id', $place->id))
            ->with('offer')
            ->orderByDesc('id');

        $cursor = KeysetCursor::decode($request->query('cursor'), self::CURSOR, 1);
        if ($cursor !== null) {
            $query->where('id', '<', KeysetCursor::intKey($cursor[0]));
        }

        $page = KeysetPage::query($query, $limit, self::CURSOR, fn (Redemption $last) => [$last->id]);

        return ApiResponse::page(RedemptionResource::collection($page->items), $page);
    }

    /**
     * Staff honour a code — the exactly-once path.
     *
     * `meta.replayed` distinguishes the call that flipped it from a repeat of
     * one already made. Both are 200: to the person at the till, a retry after a
     * timeout is not an error, and making it one teaches them to re-issue codes.
     */
    public function verify(VerifyRedemptionRequest $request, RedemptionVerifier $verifier): JsonResponse
    {
        /** @var Place $place */
        $place = $request->place();
        $location = $request->staffLocation();

        $result = $verifier->verify(
            $this->user($request),
            $request->code(),
            $place,
            $location['lat'],
            $location['lng'],
        );

        return ApiResponse::item(
            new RedemptionResource($result->redemption->load('offer')),
            ['replayed' => $result->replayed],
        );
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
