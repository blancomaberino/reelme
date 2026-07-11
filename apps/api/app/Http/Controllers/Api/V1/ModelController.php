<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\ModelCatalog;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/analysis/models — the merged model catalog the app's picker
 * renders: `auto` first, live Ollama vision models, curated OpenRouter models.
 */
class ModelController extends Controller
{
    public function __construct(private readonly ModelCatalog $catalog) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ['models' => $this->catalog->all()],
            'meta' => (object) [],
        ]);
    }
}
