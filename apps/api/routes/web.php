<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
