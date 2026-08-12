<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Support\Countries;
use App\Support\RequestLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The country catalog (T-110): every ISO 3166-1 alpha-2 code with its name in
 * the request locale (ADR-084 #2), sorted by that name.
 *
 * It exists so there is ONE localized source of country names — the profile
 * picker, the my-places filter chips and the profile payloads all read from
 * here. The alternative considered was bundling ~250 names in the app, which
 * would have needed one copy per supported language, drifted from the server's
 * spelling, and left the picker unlocalized on any future locale.
 *
 * Unpaginated on purpose: the list is fixed at 249 rows and the picker searches
 * it client-side; a cursor here would only add a round trip per keystroke.
 */
class CountryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $locale = RequestLocale::resolve($request);

        return ApiResponse::collection(
            Countries::catalog($locale),
            ['locale' => $locale],
        );
    }
}
