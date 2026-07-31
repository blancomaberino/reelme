<?php

use App\Support\KeysetCursor;
use App\Support\KeysetPage;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The `limit + 1` dance (T-105). It used to be hand-written at nine call sites,
 * and the two ways to get it wrong are both silent: trim before you count and
 * pagination ends a page early; mint the cursor from the over-fetched row and
 * every page drops a record. Neither shows up as an error — only as data the
 * user never sees — so they are pinned here.
 */

// KeysetCursor rejects a mismatched cursor with a ValidationException, which
// builds a Validator — so the app has to be up. No database is touched.
uses(TestCase::class);

/** @var Closure(int): Collection<int, object{id: int}> */
// `range(1, 0)` counts DOWN to [1, 0] rather than yielding nothing, so the
// empty case is spelled out instead of trusting range().
$rows = fn (int $n): Collection => new Collection(
    $n < 1 ? [] : array_map(fn (int $i) => (object) ['id' => $i], range(1, $n)),
);

$keys = fn (object $row) => [$row->id];

it('trims the over-fetched row and mints a cursor from the LAST KEPT one', function () use ($rows, $keys) {
    // 4 rows came back for a limit of 3: row 4 exists only to prove there is more.
    $page = KeysetPage::fromRows($rows(4), 3, 'demo', $keys);

    expect($page->items)->toHaveCount(3)
        ->and($page->items->pluck('id')->all())->toBe([1, 2, 3])
        ->and($page->nextCursor)->not->toBeNull();

    // The cursor must point at row 3, not the discarded row 4 — otherwise the
    // next page starts past a record nobody ever saw.
    expect(KeysetCursor::decode($page->nextCursor, 'demo', 1))->toBe([3]);
});

it('reports no next page when the result exactly fills the limit', function () use ($rows, $keys) {
    // The boundary the off-by-one lives on: 3 rows, limit 3 — full page, but
    // there is provably nothing after it, so offering a cursor would hand the
    // client an extra round trip that returns nothing.
    $page = KeysetPage::fromRows($rows(3), 3, 'demo', $keys);

    expect($page->items)->toHaveCount(3)
        ->and($page->nextCursor)->toBeNull();
});

it('handles a partial page and an empty one without minting a cursor', function () use ($rows, $keys) {
    expect(KeysetPage::fromRows($rows(2), 3, 'demo', $keys)->nextCursor)->toBeNull();

    $empty = KeysetPage::fromRows($rows(0), 3, 'demo', $keys);
    expect($empty->items)->toHaveCount(0)
        ->and($empty->nextCursor)->toBeNull();
});

it('never calls the key extractor when there is no next page', function () use ($rows) {
    $called = 0;
    KeysetPage::fromRows($rows(3), 3, 'demo', function (object $row) use (&$called) {
        $called++;

        return [$row->id];
    });

    // It runs on the LAST row only, and only when a cursor is actually needed —
    // so an extractor that touches a relationship costs nothing on a final page.
    expect($called)->toBe(0);
});

it('namespaces the cursor so it cannot be replayed against another sort', function () use ($rows, $keys) {
    $page = KeysetPage::fromRows($rows(4), 3, 'popular', $keys);

    expect(fn () => KeysetCursor::decode($page->nextCursor, 'recent', 1))
        ->toThrow(ValidationException::class);
});

it('wraps a cursor minted elsewhere, carrying the prev_cursor only that path has', function () use ($rows) {
    $page = KeysetPage::of($rows(2), 25, 'next-abc', 'prev-abc');

    expect($page->items)->toHaveCount(2)
        ->and($page->nextCursor)->toBe('next-abc')
        ->and($page->prevCursor)->toBe('prev-abc');
});

it('accepts a plain array of items (Laravel paginators hand back arrays)', function () {
    $page = KeysetPage::of([(object) ['id' => 1]], 25, null);

    expect($page->items)->toBeInstanceOf(Collection::class)->toHaveCount(1);
});

it('emits the pagination meta block in the documented wire shape', function () use ($rows, $keys) {
    expect(KeysetPage::fromRows($rows(4), 3, 'demo', $keys)->meta())
        ->toHaveKey('pagination')
        ->and(array_keys(KeysetPage::fromRows($rows(1), 3, 'demo', $keys)->meta()['pagination']))
        // Order matters: it is the JSON clients and the contract tests see.
        ->toBe(['next_cursor', 'prev_cursor', 'limit']);

    expect(KeysetPage::of([], 10, null)->meta()['pagination'])
        ->toBe(['next_cursor' => null, 'prev_cursor' => null, 'limit' => 10]);
});
