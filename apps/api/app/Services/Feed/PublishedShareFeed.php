<?php

namespace App\Services\Feed;

use App\Enums\ShareStatus;
use App\Models\Place;
use App\Models\Share;
use App\Support\KeysetCursor;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reverse-chronological published-share pagination (T-034/T-036): the global
 * feed and the profile share lists are the same query with an extra
 * constraint. Keyset on (published_at, id) with the strict timestamp shape
 * check (the key binds into a ?::timestamptz cast and must never 500).
 */
class PublishedShareFeed
{
    /**
     * @param  (Closure(Builder<Share>): mixed)|null  $constrain
     * @return array{items: Collection<int, Share>, next_cursor: string|null}
     */
    public function paginate(string $sortTag, ?string $cursor, int $limit, ?Closure $constrain = null): array
    {
        $keys = KeysetCursor::decode($cursor, $sortTag, 2);

        $query = Share::query()
            ->where('status', ShareStatus::Published)
            ->whereNotNull('published_place_source_id')
            ->whereNotNull('published_at')
            ->whereHas('publishedPlaceSource.place', function ($q) {
                /** @var Builder<Place> $q */
                $q->publiclyVisible();
            })
            ->with([
                'user',
                'sourcePost.influencer',
                'sourcePost.mediaAssets',
                'publishedPlaceSource.place' => fn ($q) => $q
                    ->select('places.*')
                    ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng'),
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($constrain !== null) {
            $constrain($query);
        }

        if ($keys !== null) {
            $ts = (string) $keys[0];
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $ts);
            if ($dt === false || $dt->format('Y-m-d H:i:s.u') !== $ts || str_starts_with($ts, '0000-')) {
                throw ValidationException::withMessages(['cursor' => ['The cursor is malformed.']]);
            }
            $query->whereRaw('(published_at, id) < (?::timestamptz, ?)', [$ts, KeysetCursor::intKey($keys[1])]);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return [
            'items' => $page,
            'next_cursor' => ($hasMore && $last !== null)
                ? KeysetCursor::encode($sortTag, [
                    $last->published_at?->setTimezone('UTC')->format('Y-m-d H:i:s.u') ?? '',
                    $last->id,
                ])
                : null,
        ];
    }
}
