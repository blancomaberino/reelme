<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Liveness/readiness probe. Reports a `db` flag without failing the 200
     * when the database is unreachable (degraded, not down).
     */
    public function __invoke(): JsonResponse
    {
        $db = true;

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $db = false;
        }

        return response()->json([
            'data' => [
                'status' => 'ok',
                'db' => $db,
            ],
            'meta' => (object) [],
        ]);
    }
}
