<?php

use App\Http\Controllers\Api\V1\AnalysisPreferenceController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SocialController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MapController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ModelController;
use App\Http\Controllers\Api\V1\ShareController;
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

    // Auth — 5/min per IP (03-api-design §1). Pure bearer tokens, no cookies.
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('/register', RegisterController::class);
        Route::post('/login', LoginController::class);
        Route::post('/social', SocialController::class);
        Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('/reset-password', [PasswordResetController::class, 'reset']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', LogoutController::class);
            Route::post('/refresh', RefreshController::class);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [MeController::class, 'show']);

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
