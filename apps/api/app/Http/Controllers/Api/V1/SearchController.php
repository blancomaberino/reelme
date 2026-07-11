<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Resources\InfluencerSummaryResource;
use App\Http\Resources\PlaceSummaryResource;
use App\Http\Resources\TagResource;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;

/**
 * Federated search (T-031, 03 §2.11): `GET /search?q=&types=` fans one
 * Meilisearch multi-search across places/tags/influencers and answers with
 * the summary resources. `users` is accepted-but-empty until M3 profiles.
 */
class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SearchService $search): JsonResponse
    {
        $query = (string) $request->validated('q');
        $types = $request->types();

        $outcome = $search->search($query, $types);

        $data = [];
        foreach ($outcome['results'] as $type => $models) {
            $data[$type] = match ($type) {
                'places' => PlaceSummaryResource::collection($models),
                'tags' => TagResource::collection($models),
                'influencers' => InfluencerSummaryResource::collection($models),
                default => [],
            };
        }

        return response()->json([
            'data' => $data,
            'meta' => array_filter([
                'query' => $query,
                'took_ms' => $outcome['took_ms'],
            ], fn ($v) => $v !== null),
        ]);
    }
}
