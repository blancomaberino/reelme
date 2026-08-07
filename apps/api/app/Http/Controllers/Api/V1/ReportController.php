<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Reporting content (T-049, 03 §2.16).
 *
 * The only moderation surface with a REST route. Triage, takedown and every
 * admin action live in Filament — 03 §2.16 is binding on that, and it is the
 * right split: an `/admin/*` API would be a second authorization surface for
 * the most destructive operations in the system.
 */
class ReportController extends Controller
{
    /**
     * POST /reports — 201 on a new report, 409 if you already filed this one.
     */
    public function store(StoreReportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // firstOrCreate against the unique (reporter, target, reason) is
        // race-safe without a TOCTOU pre-check: two concurrent taps on the same
        // button leave one row, and the loser reports 409 rather than failing
        // with a constraint violation the client cannot interpret.
        // Through the relation, so `reporter_user_id` comes from the session and
        // not from an array anyone could add a key to — the column is guarded
        // off `$fillable` precisely so this is the only way it gets set.
        $report = $user->reports()->firstOrCreate(
            [
                'reportable_type' => $request->validated('reportable_type'),
                'reportable_id' => (int) $request->validated('reportable_id'),
                'reason' => $request->validated('reason'),
            ],
            ['details' => $request->validated('details')],
        );

        // 409, not a silent 201: "already reported" is genuinely different
        // information, and the client says so ("Already reported — thanks")
        // rather than implying a second report was filed.
        return ApiResponse::item(
            ['report' => new ReportResource($report)],
            [],
            $report->wasRecentlyCreated ? 201 : 409,
        );
    }
}
