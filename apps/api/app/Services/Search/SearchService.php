<?php

namespace App\Services\Search;

use App\Models\Influencer;
use App\Models\Place;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Meilisearch\Client;
use Meilisearch\Contracts\SearchQuery;
use Meilisearch\Exceptions\ApiException;

/**
 * Federated search (T-031, 03 §2.11). On the meilisearch driver all requested
 * types go out as ONE multi-search round trip; on other drivers (tests, dev
 * without a search server) it falls back to per-model Scout queries. Either
 * way hits are re-hydrated from Postgres by id — the index is a lookup
 * structure, never the source of the response payload.
 */
class SearchService
{
    private const PER_TYPE_LIMIT = 10;

    /**
     * @param  list<string>  $types  subset of places|tags|influencers|users
     * @return array{results: array<string, Collection<int, covariant \Illuminate\Database\Eloquent\Model>|list<never>>, took_ms: int|null}
     */
    public function search(string $query, array $types): array
    {
        $models = [
            'places' => Place::class,
            'tags' => Tag::class,
            'influencers' => Influencer::class,
        ];
        $wanted = array_intersect_key($models, array_flip($types));

        [$idsByType, $tookMs] = config('scout.driver') === 'meilisearch'
            ? $this->multiSearchIds($query, $wanted)
            : $this->fallbackIds($query, $wanted);

        $results = [];
        foreach ($types as $type) {
            $results[$type] = match ($type) {
                'places' => $this->hydratePlaces($idsByType['places'] ?? []),
                'tags' => $this->hydrate(Tag::query(), $idsByType['tags'] ?? []),
                'influencers' => $this->hydrate(Influencer::query(), $idsByType['influencers'] ?? []),
                default => [], // `users` returns empty until M3 public profiles
            };
        }

        return ['results' => $results, 'took_ms' => $tookMs];
    }

    /**
     * One HTTP round trip for every requested index (Meilisearch multi-search).
     *
     * @param  array<string, class-string>  $wanted
     * @return array{0: array<string, list<int>>, 1: int}
     */
    private function multiSearchIds(string $query, array $wanted): array
    {
        if ($wanted === []) {
            return [[], 0];
        }

        $queries = [];
        foreach ($wanted as $model) {
            $queries[] = (new SearchQuery)
                ->setIndexUid((new $model)->searchableAs())
                ->setQuery($query)
                ->setLimit(self::PER_TYPE_LIMIT)
                ->setAttributesToRetrieve(['id']);
        }

        // Scout registers the Meilisearch client as a lazy container singleton
        // — resolving it here (not the engine's __call proxy) keeps the call typed.
        try {
            /** @var array{results: list<array{indexUid: string, hits: list<array{id: int|string}>, processingTimeMs?: int}>} $response */
            $response = app(Client::class)->multiSearch($queries);
        } catch (ApiException $e) {
            if ($e->errorCode === 'index_not_found') {
                return [[], 0]; // indexes not provisioned yet — run reelmap:search:reindex
            }
            throw $e;
        }

        $byIndex = [];
        $tookMs = 0;
        foreach ($response['results'] as $result) {
            $byIndex[$result['indexUid']] = array_map(fn ($hit) => (int) $hit['id'], $result['hits']);
            $tookMs += (int) ($result['processingTimeMs'] ?? 0);
        }

        $ids = [];
        foreach ($wanted as $type => $model) {
            $ids[$type] = $byIndex[(new $model)->searchableAs()] ?? [];
        }

        return [$ids, $tookMs];
    }

    /**
     * Driver-agnostic fallback: one Scout query per type.
     *
     * @param  array<string, class-string>  $wanted
     * @return array{0: array<string, list<int>>, 1: null}
     */
    private function fallbackIds(string $query, array $wanted): array
    {
        $ids = [];
        foreach ($wanted as $type => $model) {
            $ids[$type] = $model::search($query)->take(self::PER_TYPE_LIMIT)->keys()
                ->map(fn ($id) => (int) $id)->all();
        }

        return [$ids, null];
    }

    /**
     * Places need their coordinate aliases + tags for PlaceSummaryResource.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Place>
     */
    private function hydratePlaces(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return Place::query()
            ->publiclyVisible()
            ->whereIn('id', $ids)
            ->select('places.*')
            ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
            ->get()
            ->sortBy(fn (Place $p) => array_search($p->id, $ids, true))
            ->values();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $ids
     * @return Collection<int, TModel>
     */
    private function hydrate($query, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<int, TModel> $models */
        $models = $query->whereIn('id', $ids)->get();

        return $models
            ->sortBy(fn ($m) => array_search((int) $m->getKey(), $ids, true))
            ->values();
    }
}
