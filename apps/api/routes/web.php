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
