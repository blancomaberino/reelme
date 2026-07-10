<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AI\ModelCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/me/analysis-preference — set the user's preferred analysis model.
 * Validated against `auto` + the live local tags + the curated remote list so an
 * unknown or undriveable id is rejected with 422 (03-api-design §2.5).
 */
class AnalysisPreferenceController extends Controller
{
    public function __construct(private readonly ModelCatalog $catalog) {}

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => ['required', 'string', Rule::in($this->catalog->selectableIds())],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->preferred_analysis_model = $validated['model'] === 'auto' ? null : $validated['model'];
        $user->save();

        return response()->json([
            'data' => ['user' => new UserResource($user)],
            'meta' => (object) [],
        ]);
    }
}
