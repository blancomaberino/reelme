<?php

use App\Http\Controllers\Api\V1\AnalysisPreferenceController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SocialController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FeedDismissalController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\GdprController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InfluencerClaimController;
use App\Http\Controllers\Api\V1\InfluencerController;
use App\Http\Controllers\Api\V1\InfluencerDashboardController;
use App\Http\Controllers\Api\V1\InviteController;
use App\Http\Controllers\Api\V1\MapController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MePlacesController;
use App\Http\Controllers\Api\V1\ModelController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\PlaceClaimController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\PlaceListController;
use App\Http\Controllers\Api\V1\PlatformAccountController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RedemptionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\ShareController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Http\Controllers\Api\V1\UserPlaceTagController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\MediaUploadController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| All endpoints live under /api/v1. Versioning is via the URL path; breaking
| changes ship as /api/v2 (see 03-api-design.md §1). Controllers live in the
| App\Http\Controllers\Api\V1 namespace. Admin is Filament-only — never add
| /api/v1/admin/* routes here.
*/
Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    // Map read path (T-029): public + viewport-scoped; 120/min. Optional auth —
    // `filter=mine|following` resolve the caller via the sanctum guard inside the
    // controller (401 when absent) without gating the public `all` view.
    Route::get('/map/places', [MapController::class, 'places'])->middleware('throttle:map');

    // Places browse surface (T-030, 03 §2.6): public index with filters +
    // detail + attribution sources. `{place}` binds by slug (canonical) or
    // numeric id. Same 120/min map limiter.
    Route::get('/places', [PlaceController::class, 'index'])->middleware('throttle:map');
    // Distinct card/bank/wallet discounts across visible places (T-079) — the
    // facet source for the map's "filter by card" chips. Before `{place}` so the
    // literal path isn't captured as a slug.
    Route::get('/places/payment-cards', [PlaceController::class, 'paymentCards'])->middleware('throttle:map');
    Route::get('/places/{place}', [PlaceController::class, 'show'])->middleware('throttle:map');
    Route::get('/places/{place}/sources', [PlaceController::class, 'sources'])->middleware('throttle:map');

    // Restaurant offers (T-042, 03 §2.12): public browse + detail. `?mine=1`
    // turns the index into the operator's management view — the controller
    // resolves that caller via the sanctum guard, so the route stays public.
    // Writes are registered in the authenticated group below.
    Route::get('/offers', [OfferController::class, 'index'])->middleware('throttle:map');
    Route::get('/offers/{offer}', [OfferController::class, 'show'])->middleware('throttle:map');

    // Tags + federated search (T-031, 03 §2.11): public, same interactive
    // read limiter as the map (typing in a search box pans like a map does).
    Route::get('/tags', [TagController::class, 'index'])->middleware('throttle:map');
    Route::get('/search', SearchController::class)->middleware('throttle:map');

    // Native reviews (T-059): public read; writes are authenticated below.
    Route::get('/places/{place}/reviews', [ReviewController::class, 'index'])->middleware('throttle:map');

    // Discovery feed (T-034, 03 §2.8): global scope is public; `following`
    // requires auth (checked in the controller via the sanctum guard).
    Route::get('/feed', [FeedController::class, 'index'])->middleware('throttle:map');

    // Public profiles (T-036, 03 §2.9): users bind by citext username;
    // private profiles 404 in-controller. Influencer identities are always
    // public. Same interactive read limiter.
    Route::middleware('throttle:map')->group(function () {
        Route::get('/users/{user:username}', [ProfileController::class, 'show']);
        Route::get('/users/{user:username}/map', [ProfileController::class, 'map']);
        // Their places list + public Lists (T-071): the list view of their map,
        // and their public collections. Same private-profile 404 gate.
        Route::get('/users/{user:username}/places', [ProfileController::class, 'places']);
        Route::get('/users/{user:username}/lists', [ProfileController::class, 'lists']);
        // Followers / following lists (T-039). Same private-profile 404 gate.
        Route::get('/users/{user:username}/followers', [ProfileController::class, 'followers']);
        Route::get('/users/{user:username}/following', [ProfileController::class, 'following']);
        Route::get('/influencers/{influencer}', [InfluencerController::class, 'show']);
        Route::get('/influencers/{influencer}/map', [InfluencerController::class, 'map']);

        // Shared lists (T-063): public read of a list by its global public_slug.
        // A private/never-shared list 404s (privacy in PublicListShowRequest).
        Route::get('/lists/{list:public_slug}', [PlaceListController::class, 'publicShow']);
    });

    // Auth — 5/min per IP (03-api-design §1). Pure bearer tokens, no cookies.
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('/register', RegisterController::class);
        Route::post('/login', LoginController::class);
        Route::post('/social', SocialController::class);
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('/reset-password', [PasswordResetController::class, 'reset']);

        // Email confirmation (T-066): confirm with the 6-digit code (+ get a
        // token) or resend it. Public — an unverified account can't sign in.
        Route::post('/verify-email', [EmailVerificationController::class, 'verify']);
        Route::post('/resend-verification', [EmailVerificationController::class, 'resend']);

        // Second factor (T-068). PUBLIC by necessity — the caller has no session
        // yet; the challenge token issued by /login is the proof that the
        // password step passed. Sharing the `throttle:auth` bucket caps guessing
        // per IP, on top of the per-challenge attempt budget in TwoFactorService.
        Route::post('/two-factor-challenge', TwoFactorChallengeController::class);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', LogoutController::class);
            Route::post('/refresh', RefreshController::class);
        });
    });

    // Stripe webhooks (T-045, 03 §4.1). PUBLIC by necessity — Stripe carries no
    // bearer token, so the SIGNATURE is the authentication. Outside every auth
    // group, and outside the standard throttles: Stripe retries aggressively on
    // a non-2xx, and rate-limiting it would turn a burst into a retry storm.
    Route::post('/webhooks/stripe', StripeWebhookController::class)
        ->withoutMiddleware(['throttle:api']);

    // Platform-account OAuth callback (T-015, 03 §2.3). PUBLIC — the provider
    // redirects here unauthenticated; the controller re-binds it to the user via
    // a signed, single-use state nonce. Throttled like the other auth surfaces.
    Route::get('/platform-accounts/{platform}/callback', [PlatformAccountController::class, 'callback'])
        ->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me', [MeController::class, 'update']);

        // Reporting content (T-049, 03 §2.16). The ONLY moderation REST route:
        // triage and takedown are Filament-only, deliberately. Shares the
        // `reviews` limiter — both are spam-adjacent user writes, and a flooded
        // moderation queue is as bad as flooded reviews.
        Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:reviews');

        // Data rights (T-050, 03 §2.2). Throttled hard — neither is a button
        // anyone presses twice on purpose, and both are expensive: one queues a
        // full-archive build, the other ends an account.
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('/me/export', [GdprController::class, 'export']);
            Route::delete('/me', [GdprController::class, 'destroy']);
        });

        // Restaurant-owner claiming (T-041, 06 §2.1). Acts on the caller's own
        // claim only — no user id in any signature. Throttled harder than the
        // other writes because starting a phone claim places a real call.
        Route::middleware('throttle:10,1')->group(function () {
            Route::get('/places/{place}/claim', [PlaceClaimController::class, 'show']);
            Route::post('/places/{place}/claim', [PlaceClaimController::class, 'store']);
            Route::post('/places/{place}/claim/verify', [PlaceClaimController::class, 'verify']);
        });

        // Wallet + payouts (T-045, 03 §2.14). Reads are on the interactive
        // limiter; the payout request is throttled hard — it moves real money
        // and each call creates a Stripe Transfer.
        Route::middleware('throttle:map')->group(function () {
            Route::get('/wallet', [WalletController::class, 'show']);
            Route::get('/wallet/ledger', [WalletController::class, 'ledger']);
            Route::get('/wallet/payouts', [WalletController::class, 'payouts']);
            Route::get('/wallet/connect/status', [WalletController::class, 'connectStatus']);

            // The influencer funnel (T-048, 06 §5.2). Sits beside the wallet
            // because it answers the other half of the same question — the
            // wallet says how much, this says which posts earned it. Same
            // interactive limiter; the expensive part is cached per identity.
            Route::get('/me/influencer/dashboard', [InfluencerDashboardController::class, 'show']);
        });
        Route::middleware('throttle:10,1')->group(function () {
            // "Create or refresh" — links expire in minutes and are single-use.
            Route::post('/wallet/connect/onboarding-link', [WalletController::class, 'onboardingLink']);
        });
        Route::middleware('throttle:5,1')->group(function () {
            Route::post('/wallet/payouts', [WalletController::class, 'requestPayout']);
        });

        // Redemptions (T-043, 03 §2.13, 06 §3) — the payable event.
        //
        // Issue is throttled hard: it mints a bearer token a restaurant will
        // honour, and 06 §3's per-diner velocity limits (3/day, 10/week) live in
        // RedemptionGuards on top of this. Verify is 30/min per staff ACCOUNT —
        // a busy till is bursty, while the hourly cap in RedemptionGuards is
        // what actually bounds a grinding attack.
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/redemptions', [RedemptionController::class, 'store']);
        });
        // Keyed on the staff ACCOUNT (T-051), not on a raw per-IP bucket: one
        // till behind a shop's NAT must not throttle the shop next door.
        Route::middleware('throttle:verify')->group(function () {
            Route::post('/redemptions/verify', [RedemptionController::class, 'verify']);
        });
        // Reads carry the same interactive limiter as the other read surfaces.
        // Two of them return BEARER CREDENTIALS to their owner (a live code is a
        // free meal), so an unbounded read rate is worth closing even though
        // RedemptionPolicy authorizes every row.
        Route::middleware('throttle:map')->group(function () {
            // Registered AFTER /redemptions/verify so the literal segment can
            // never be captured as an id.
            Route::get('/redemptions/{redemption}', [RedemptionController::class, 'show']);
            Route::get('/me/redemptions', [RedemptionController::class, 'index']);
            // The venue's own log — operator-only, and without codes.
            Route::get('/places/{place}/redemptions', [RedemptionController::class, 'forPlace']);
        });

        // Offer management (T-042, 06 §2.2). Owner-only via OfferPolicy — every
        // check re-derives operator status from the verified place claim, so a
        // revoked claim revokes offer control with it. DELETE archives.
        // Same light write throttle as the other management surfaces.
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/offers', [OfferController::class, 'store']);
            Route::patch('/offers/{offer}', [OfferController::class, 'update']);
            Route::delete('/offers/{offer}', [OfferController::class, 'destroy']);
        });

        // Managing your own second factor (T-068). Acts on the authenticated
        // user only — no id in any signature. The destructive three re-ask for
        // the password inside the controller. Throttled like the other small
        // write surfaces; the login challenge itself lives in the auth group.
        Route::middleware('throttle:30,1')->prefix('two-factor')->group(function () {
            Route::get('/', [TwoFactorController::class, 'show']);
            Route::post('/enable', [TwoFactorController::class, 'enable']);
            Route::post('/confirm', [TwoFactorController::class, 'confirm']);
            Route::post('/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
            Route::post('/recovery-codes/regenerate', [TwoFactorController::class, 'regenerateRecoveryCodes']);
            Route::delete('/', [TwoFactorController::class, 'disable']);
        });

        // Expo push-token registration (T-027). {device} is the numeric id OR the
        // raw token (logout convenience). Light write throttle like the other
        // small write surfaces.
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/devices', [DeviceController::class, 'store']);
            Route::delete('/devices/{device}', [DeviceController::class, 'destroy']);
        });

        // Linked platform accounts (T-015): list / start-link / unlink. The
        // unauthenticated OAuth callback is registered publicly above. A light
        // write throttle matches the other write surfaces.
        Route::middleware('throttle:30,1')->group(function () {
            Route::get('/platform-accounts', [PlatformAccountController::class, 'index']);
            Route::post('/platform-accounts/{platform}/link', [PlatformAccountController::class, 'link']);
            Route::delete('/platform-accounts/{platformAccount}', [PlatformAccountController::class, 'destroy']);
        });

        // The personal "my places" list (T-071): the filterable list view of my
        // map — places I shared (not soft-hidden) ∪ places I saved. Replaces the
        // removed global feed as the app's primary browse surface.
        Route::get('/me/places', [MePlacesController::class, 'index'])->middleware('throttle:map');
        // Discovery-tag facet of my places (ADR-084): the tags actually on my
        // places, for the filter autocomplete. Registered before any {place}
        // route so the literal "tags" segment can never be read as a place.
        Route::get('/me/places/tags', [MePlacesController::class, 'tags'])->middleware('throttle:map');
        // Country + type facets of my places (T-088): distinct values over the FULL
        // collection so the filter chips aren't capped at the first loaded page.
        // Literal segment registered before any {place} route (see "tags" above).
        Route::get('/me/places/facets', [MePlacesController::class, 'facets'])->middleware('throttle:map');
        // The venues I OPERATE (T-042) — a different sense of "mine" from the
        // routes above (shared/saved): places I hold a verified claim on. Powers
        // the restaurant offer screens' venue picker.
        Route::get('/me/venues', [MePlacesController::class, 'venues'])->middleware('throttle:map');
        // Remove a place from my collection (soft-hide my shares + un-save).
        Route::delete('/me/places/{place}', [MePlacesController::class, 'destroy']);

        // Analysis model catalog + per-user model preference (T-020).
        Route::get('/analysis/models', [ModelController::class, 'index']);
        Route::put('/me/analysis-preference', [AnalysisPreferenceController::class, 'update']);

        // Shares (ingest). POST is rate-limited 10/min + 100/day (03 §1).
        Route::post('/shares', [ShareController::class, 'store'])->middleware('throttle:shares');
        Route::get('/shares', [ShareController::class, 'index']);
        // AnalysisStatus polls this every 2.5s (24/min) while an ingest runs.
        // Its own, higher bucket (T-051): on the default limiter one screen
        // would consume most of a minute's budget and the app would throttle a
        // user for using it exactly as designed.
        Route::get('/shares/{share}', [ShareController::class, 'show'])->middleware('throttle:polling');
        Route::patch('/shares/{share}', [ShareController::class, 'update']);
        Route::post('/shares/{share}/retry', [ShareController::class, 'retry']);
        Route::post('/shares/{share}/publish-best-guess', [ShareController::class, 'publishBestGuess']);
        // Resolve/dismiss a still-pending venue on a partially-published multi-place
        // share (T-071) — {index} is the stable extraction index in pending[].
        Route::post('/shares/{share}/pending/{index}/resolve', [ShareController::class, 'resolvePending'])->whereNumber('index');
        Route::delete('/shares/{share}/pending/{index}', [ShareController::class, 'dismissPending'])->whereNumber('index');
        Route::delete('/shares/{share}', [ShareController::class, 'destroy']);

        // Invite friends to Reelmap by email (T-069). Abuse-sensitive (sends
        // mail) → 10 requests/hour, ≤20 addresses each.
        Route::post('/invites', [InviteController::class, 'store'])->middleware('throttle:10,60');

        // Influencer identity claiming (T-038, 06 §5.1): OAuth handle match or
        // code-in-bio. GET resumes an in-progress claim; POST starts/advances it.
        // A light write throttle matches the other write surfaces; the bio-verify
        // action additionally self-limits its remote profile fetch in-controller.
        Route::middleware('throttle:30,1')->group(function () {
            // Notification center (T-040, 03 §2.15) — the durable twin of the
            // T-027 pushes. Every query is scoped from $user->notifications().
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/read', [NotificationController::class, 'read']);

            Route::get('/influencers/{influencer}/claim', [InfluencerClaimController::class, 'show']);
            Route::post('/influencers/{influencer}/claim', [InfluencerClaimController::class, 'store']);
        });

        // Follows (T-037, 03 §2.10): follow users or influencers; counters +
        // NewFollower notification handled transactionally in the controller.
        Route::post('/follows', [FollowController::class, 'store']);
        Route::delete('/follows/{follow}', [FollowController::class, 'destroy']);
        Route::get('/me/follows', [FollowController::class, 'follows']);

        // "Hide from my feed": per-user, non-destructive dismiss of a published
        // share. The feed query filters these out for the viewer only. A light
        // write throttle keeps it in line with the other write surfaces.
        Route::middleware('throttle:60,1')->group(function () {
            Route::post('/feed/hidden', [FeedDismissalController::class, 'store']);
            Route::delete('/feed/hidden/{share}', [FeedDismissalController::class, 'destroy']);
        });

        // Personal place lists (T-062): owner-scoped collections. A light write
        // throttle matches the other write surfaces.
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('/me/lists', [PlaceListController::class, 'index']);
            Route::post('/me/lists', [PlaceListController::class, 'store']);
            Route::get('/me/lists/{list}', [PlaceListController::class, 'show']);
            Route::patch('/me/lists/{list}', [PlaceListController::class, 'update']);
            Route::delete('/me/lists/{list}', [PlaceListController::class, 'destroy']);
            Route::post('/me/lists/{list}/places/{place}', [PlaceListController::class, 'addPlace']);
            Route::delete('/me/lists/{list}/places/{place}', [PlaceListController::class, 'removePlace']);
            // Save-a-copy of a shared list into the caller's own lists (T-063);
            // {slug} is the SOURCE list's public_slug, not an owned list.
            Route::post('/me/lists/{slug}/copy', [PlaceListController::class, 'copy']);
        });

        // Private per-user place tags (T-064): personal annotations (e.g.
        // "visitar a las 5"), owner-only and never aggregated into the public
        // discovery tags. Same light write throttle as the other write surfaces.
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('/me/places/{place}/tags', [UserPlaceTagController::class, 'index']);
            Route::post('/me/places/{place}/tags', [UserPlaceTagController::class, 'store']);
            Route::delete('/me/places/{place}/tags/{tag}', [UserPlaceTagController::class, 'destroy']);
        });

        // Native reviews (T-059): one review per (place, user). POST creates
        // (409 on duplicate), PUT upserts, DELETE removes the caller's own.
        // Spam-adjacent writes → 10/min + 100/day per user.
        Route::middleware('throttle:reviews')->group(function () {
            Route::post('/places/{place}/reviews', [ReviewController::class, 'store']);
            Route::put('/places/{place}/reviews', [ReviewController::class, 'upsert']);
            Route::delete('/places/{place}/reviews', [ReviewController::class, 'destroy']);
            Route::post('/reviews/{review}/report', [ReviewController::class, 'report']);
        });
    });
});

// Signed local-dev media upload target (see MediaUrlService). Registered only
// outside production — R2 uses native presigned uploads, so this route is never
// legitimately signed in prod. Not a versioned API endpoint.
if (! app()->isProduction()) {
    Route::put('/media/upload', MediaUploadController::class)
        ->middleware('signed')
        ->name('media.upload');
}
