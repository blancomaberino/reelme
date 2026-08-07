<?php

namespace App\Services\Moderation;

use App\Enums\ReportStatus;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Report;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What an admin can do about a report (T-049).
 *
 * Deliberately thin, because the take-downs already exist: T-072 shipped
 * {@see ShareModerator} and {@see PlaceModerator}, and T-008 shipped the ban.
 * This class routes a report's polymorphic target to the right one and records
 * the decision — it does not re-implement any of them. A second way to hide a
 * place would diverge from the first, and the newer one is always the worse one.
 *
 * Every method closes the report, because a moderation action with no record of
 * why it happened is indistinguishable from an admin acting on a whim.
 */
class ReportActions
{
    public function __construct(
        private readonly ShareModerator $shares,
        private readonly PlaceModerator $places,
    ) {}

    /**
     * Take the reported content down and resolve the report.
     *
     * Returns false when the target no longer exists (already removed, or
     * deleted by its owner in the meantime) — the report is still resolved,
     * because there is genuinely nothing left to do about it.
     */
    public function takeDown(Report $report, User $admin, string $note): bool
    {
        $target = $report->reportable;
        $acted = $target !== null && $this->applyTakeDown($target);

        $this->close($report, ReportStatus::Resolved, $admin, $note, $acted ? 'take_down' : 'take_down_noop');

        return $acted;
    }

    /**
     * Ban the account behind the report and resolve it.
     *
     * Reuses the T-008 mechanism exactly — revoke every token, then soft-delete
     * — rather than inventing a second one. `deletion_requested_at` is left
     * alone: a ban is not a deletion request, and conflating the two is what
     * ADR-050 exists to prevent.
     */
    public function banReporter(Report $report, User $admin, string $note): bool
    {
        $target = $report->reportable;

        if (! $target instanceof User || $target->trashed() || $target->is($admin)) {
            return false;
        }

        DB::transaction(function () use ($target): void {
            $target->tokens()->delete();
            $target->delete();
        });

        $this->close($report, ReportStatus::Resolved, $admin, $note, 'ban');

        return true;
    }

    /** Looked at it, nothing to do. */
    public function dismiss(Report $report, User $admin, string $note): void
    {
        $this->close($report, ReportStatus::Dismissed, $admin, $note, 'dismiss');
    }

    /**
     * Resolve every other open report against the same target in one move.
     *
     * Six reports about one share are one decision, and making an admin close
     * them individually is how a queue stops being worked. Returns how many
     * were swept.
     */
    public function resolveSiblings(Report $report, User $admin, string $note): int
    {
        $siblings = Report::query()->where(function ($q) use ($report) {
            $q->where('reportable_type', $report->reportable_type)
                ->where('reportable_id', $report->reportable_id)
                ->whereKeyNot($report->getKey());
        })->open()->get();

        foreach ($siblings as $sibling) {
            $this->close($sibling, $report->status, $admin, $note, 'sibling_of:'.$report->id);
        }

        return $siblings->count();
    }

    /**
     * Route the target to the moderator that owns its take-down.
     *
     * A `source_post` has no take-down of its own — it is shared across users,
     * and removing it would take other people's places with it. Copyright
     * complaints about one belong in the takedown flow, which unpublishes the
     * shares that cite it. Same for an offer: it is the venue's record, and
     * pulling it is an offer-status change, not a moderation hide.
     */
    private function applyTakeDown(Model $target): bool
    {
        return match (true) {
            $target instanceof Share => tap(true, fn () => $this->shares->takeDown($target)),
            $target instanceof Place => tap(true, fn () => $this->places->takeDown([$target])),
            $target instanceof User => false,
            $target instanceof SourcePost, $target instanceof Offer => false,
            default => false,
        };
    }

    private function close(Report $report, ReportStatus $status, User $admin, string $note, string $action): void
    {
        $report->resolve($status, $admin);

        // The audit trail. `note` is required by the Filament action, so this
        // always carries a human's stated reason — the thing a takedown dispute
        // or a store review actually asks for.
        Log::info('moderation.report.actioned', [
            'report_id' => $report->id,
            'action' => $action,
            'status' => $status->value,
            'admin_id' => $admin->id,
            'reportable_type' => $report->reportable_type,
            'reportable_id' => $report->reportable_id,
            'note' => $note,
        ]);
    }
}
