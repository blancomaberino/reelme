<?php

use App\Http\Controllers\Api\V1\HealthController;
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
});
