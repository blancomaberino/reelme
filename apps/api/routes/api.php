<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SocialController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
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
