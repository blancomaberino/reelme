<?php

use App\Http\ApiResponse;
use App\Support\KeysetPage;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

/**
 * The `{data, meta}` envelope (03 §1, consolidated in T-105).
 *
 * The load-bearing detail is the `(object)` cast on `meta`: PHP encodes an
 * empty array as `[]`, and the contract says `{}`. Thirty-odd hand-written
 * envelopes each carried their own copy of that cast, so each was one
 * omission away from shipping the wrong JSON type. Asserting on the encoded
 * STRING rather than the decoded array is deliberate — decoding erases exactly
 * the distinction under test.
 */
// Boots the app — ApiResponse goes through the response factory — but not the
// database, since nothing here touches a table.
uses(TestCase::class);

$encoded = fn (JsonResponse $response): string => (string) $response->getContent();

it('encodes an empty meta as an object, not an array', function () use ($encoded) {
    expect($encoded(ApiResponse::item(['id' => '1'])))
        ->toBe('{"data":{"id":"1"},"meta":{}}');
});

it('keeps data null rather than dropping the key', function () use ($encoded) {
    // Clients parse the envelope on every response; a missing `data` is a
    // different shape from a null one.
    expect($encoded(ApiResponse::noContent()))->toBe('{"data":null,"meta":{}}');
});

it('carries meta on a no-content write (a delete that returns fresh aggregates)', function () use ($encoded) {
    expect($encoded(ApiResponse::noContent(['rating' => ['count' => 0]])))
        ->toBe('{"data":null,"meta":{"rating":{"count":0}}}');
});

it('defaults to 200 and honours an explicit status', function () {
    expect(ApiResponse::item(null)->getStatusCode())->toBe(200)
        ->and(ApiResponse::item(['id' => '1'], [], 201)->getStatusCode())->toBe(201)
        ->and(ApiResponse::collection([], [], 202)->getStatusCode())->toBe(202);
});

it('puts endpoint meta before pagination, the order clients already see', function () use ($encoded) {
    $page = KeysetPage::of([], 25, 'abc');

    expect($encoded(ApiResponse::page([], $page, ['scope' => 'following'])))
        ->toBe('{"data":[],"meta":{"scope":"following","pagination":{"next_cursor":"abc","prev_cursor":null,"limit":25}}}');
});

it('never lets endpoint meta clobber the pagination block', function () use ($encoded) {
    // array_merge order matters: a caller passing a stale `pagination` key must
    // lose to the page's own, not silently overwrite it.
    $page = KeysetPage::of([], 10, null);
    $response = ApiResponse::page([], $page, ['pagination' => ['next_cursor' => 'stale']]);

    expect($encoded($response))
        ->toBe('{"data":[],"meta":{"pagination":{"next_cursor":null,"prev_cursor":null,"limit":10}}}');
});

it('emits an empty collection as [] — an empty LIST is not an empty object', function () use ($encoded) {
    expect($encoded(ApiResponse::collection([])))->toBe('{"data":[],"meta":{}}');
});
