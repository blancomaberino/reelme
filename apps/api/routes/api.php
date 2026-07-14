<?php

use App\Http\Controllers\Api\V1\AnalysisPreferenceController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SocialController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\FeedDismissalController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InfluencerController;
use App\Http\Controllers\Api\V1\MapController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ModelController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\PlaceListController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\ShareController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\UserPlaceTagController;
use App\Http\Controllers\MediaUploadController;
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
    Route::get('/places/{place}', [PlaceController::class, 'show'])->middleware('throttle:map');
    Route::get('/places/{place}/sources', [PlaceController::class, 'sources'])->middleware('throttle:map');

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

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', LogoutController::class);
            Route::post('/refresh', RefreshController::class);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me', [MeController::class, 'update']);

        // Analysis model catalog + per-user model preference (T-020).
        Route::get('/analysis/models', [ModelController::class, 'index']);
        Route::put('/me/analysis-preference', [AnalysisPreferenceController::class, 'update']);

        // Shares (ingest). POST is rate-limited 10/min + 100/day (03 §1).
        Route::post('/shares', [ShareController::class, 'store'])->middleware('throttle:shares');
        Route::get('/shares', [ShareController::class, 'index']);
        Route::get('/shares/{share}', [ShareController::class, 'show']);
        Route::patch('/shares/{share}', [ShareController::class, 'update']);
        Route::post('/shares/{share}/retry', [ShareController::class, 'retry']);
        Route::delete('/shares/{share}', [ShareController::class, 'destroy']);

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
