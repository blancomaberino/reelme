<?php

use App\Http\Controllers\Legal\LegalDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Legal documents (T-054)
|--------------------------------------------------------------------------
| The privacy policy and terms of service, public and unauthenticated. Both
| stores require a reachable URL for the policy before a build can be
| submitted, and Apple additionally requires it to be linked from inside the
| app (mobile: Settings → Legal).
|
| Two shapes per document on purpose: the bare `/privacy` negotiates the
| locale from Accept-Language, which is what a human following a link wants;
| `/privacy/en` pins one, which is what you paste into a per-locale field in
| App Store Connect and what the app links to so the page matches the language
| the user chose in-app.
*/
Route::controller(LegalDocumentController::class)->group(function () {
    Route::get('/privacy', 'privacy')->name('legal.privacy');
    Route::get('/privacy/{locale}', 'privacy')
        ->whereIn('locale', LegalDocumentController::LOCALES)
        ->name('legal.privacy.locale');
    Route::get('/terms', 'terms')->name('legal.terms');
    Route::get('/terms/{locale}', 'terms')
        ->whereIn('locale', LegalDocumentController::LOCALES)
        ->name('legal.terms.locale');
});

// Shared-list web page (T-063): the human-facing URL behind a shared list. It
// client-fetches the public GET /api/v1/lists/{slug} read (privacy stays in the
// API) and offers a deep link into the app. The slug is validated to the minted
// public_slug charset so nothing untrusted reaches the view.
Route::get('/l/{slug}', fn (string $slug) => view('list-share', ['slug' => $slug]))
    ->where('slug', '[A-Za-z0-9\-]+')
    ->name('list.share');

/*
|--------------------------------------------------------------------------
| Stripe Connect onboarding return (T-045)
|--------------------------------------------------------------------------
| Stripe rejects a non-HTTPS account-link URL in live mode, so these HTTPS
| routes are what it is given; they immediately bounce into the `reelmap://`
| deep link the mobile webview intercepts (05 screen #22).
|
| Neither carries any authority: Stripe sends the operator here regardless of
| the outcome, so nothing may be inferred from arriving. The app re-reads the
| live Connect status on return, which is the only thing that decides whether
| payouts are actually enabled.
*/
Route::get('/connect/{outcome}', function (string $outcome) {
    $target = $outcome === 'refresh' ? 'refresh' : 'return';

    return redirect()->away('reelmap://wallet/connect/'.$target);
})->whereIn('outcome', ['return', 'refresh'])->name('connect.redirect');
