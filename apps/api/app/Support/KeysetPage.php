<?php

namespace App\Support;

use App\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * One page of a keyset-paginated list (T-105).
 *
 * Every index endpoint used to hand-roll the same four steps — fetch
 * `limit + 1`, compare the count to decide `hasMore`, trim to `limit`, mint a
 * cursor from the last surviving row — in nine places and four dialects. The
 * off-by-one is easy to get subtly wrong (trimming before deciding `hasMore`
 * silently ends pagination one page early), so it lives here once.
 *
 * Pairs with {@see KeysetCursor}, which owns the cursor's wire format and its
 * validation, and with {@see ApiResponse}, which turns `meta()` into
 * the response envelope.
 *
 * @template TItem
 */
final class KeysetPage
{
    /**
     * @param  Collection<int, TItem>  $items
     */
    private function __construct(
        public readonly Collection $items,
        public readonly int $limit,
        public readonly ?string $nextCursor,
        public readonly ?string $prevCursor = null,
    ) {}

    /**
     * Run a keyset query: over-fetch by one, trim, and mint the next cursor.
     *
     * `$keys` returns the ORDER BY values of the page's last row, in sort
     * order — the encode-side companion to whatever WHERE the caller applied.
     * It is only called when there IS a next page, so it never runs on an
     * empty result.
     *
     * Takes a Relation as readily as a Builder: half the callers paginate a
     * relationship (`$place->reviews()`, `$user->follows()`) and forcing a
     * `->getQuery()` at those call sites would buy nothing but noise.
     *
     * @template TModel of Model
     * @template TDeclaring of Model  the relation's parent, when one was passed
     * @template TResult  the relation's own result type (Collection, a model, …)
     *
     * @param  Builder<TModel>|Relation<TModel, TDeclaring, TResult>  $query  already ordered and cursor-constrained
     * @param  string  $namespace  cursor namespace; a mismatch 422s on decode
     * @param  callable(TModel): list<int|float|string>  $keys
     * @return self<TModel>
     */
    public static function query(Builder|Relation $query, int $limit, string $namespace, callable $keys): self
    {
        /** @var Collection<int, TModel> $rows */
        $rows = $query->limit($limit + 1)->get();

        return self::fromRows($rows, $limit, $namespace, $keys);
    }

    /**
     * Same, for rows the caller already fetched with the `limit + 1`
     * convention (a query it had to run itself, or a hydrated collection).
     *
     * @template TRow
     *
     * @param  Collection<int, TRow>  $rows  exactly the result of a `limit + 1` fetch
     * @param  callable(TRow): list<int|float|string>  $keys
     * @return self<TRow>
     */
    public static function fromRows(Collection $rows, int $limit, string $namespace, callable $keys): self
    {
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->values();
        $last = $items->last();

        return new self(
            $items,
            $limit,
            ($hasMore && $last !== null) ? KeysetCursor::encode($namespace, $keys($last)) : null,
        );
    }

    /**
     * Wrap items whose cursor was minted elsewhere — a service that paginates
     * internally (the feed) or Laravel's own `cursorPaginate()`, which is the
     * one caller with a `prev_cursor`.
     *
     * @template TRow
     *
     * @param  Collection<int, TRow>|array<int, TRow>  $items
     * @return self<TRow>
     */
    public static function of(Collection|array $items, int $limit, ?string $nextCursor, ?string $prevCursor = null): self
    {
        return new self(
            $items instanceof Collection ? $items : new Collection($items),
            $limit,
            $nextCursor,
            $prevCursor,
        );
    }

    /**
     * The `meta.pagination` block, in the wire order every endpoint already
     * emits. Merged into the envelope by {@see ApiResponse::page()}.
     *
     * @return array{pagination: array{next_cursor: string|null, prev_cursor: string|null, limit: int}}
     */
    public function meta(): array
    {
        return [
            'pagination' => [
                'next_cursor' => $this->nextCursor,
                'prev_cursor' => $this->prevCursor,
                'limit' => $this->limit,
            ],
        ];
    }
}
