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

        // One transaction around the mutation AND its record. Separately, a
        // throw between them leaves content hidden with nothing saying who hid
        // it or why — which this class's whole docblock argues is the failure
        // to avoid. The moderators open their own nested transactions; Laravel
        // folds those into this one.
        $acted = DB::transaction(function () use ($report, $target, $admin): bool {
            $acted = $target !== null && $this->applyTakeDown($target);

            $this->record($report, ReportStatus::Resolved, $admin, $acted ? 'take_down' : 'take_down_noop');

            return $acted;
        });

        // Logged only after the commit, so a rolled-back action is never
        // written down as done.
        $this->audit($report, ReportStatus::Resolved, $admin, $note, $acted ? 'take_down' : 'take_down_noop');

        return $acted;
    }

    /**
     * Ban the REPORTED account — never the reporter — and resolve the report.
     *
     * Named for what it does. `banReporter` was the original name and it is the
     * single worst one available here: the next caller reads it as "ban the
     * person who filed this" and ships the exact opposite of moderation.
     *
     * Reuses the T-008 mechanism exactly — revoke every token, then soft-delete
     * — rather than inventing a second one. `deletion_requested_at` is left
     * alone: a ban is not a deletion request, and conflating the two is what
     * ADR-050 exists to prevent.
     */
    public function banReported(Report $report, User $admin, string $note): bool
    {
        $target = $report->reportable;

        if (! $target instanceof User || $target->trashed() || $target->is($admin)) {
            return false;
        }

        DB::transaction(function () use ($report, $target, $admin): void {
            $target->tokens()->delete();
            $target->delete();

            $this->record($report, ReportStatus::Resolved, $admin, 'ban');
        });

        $this->audit($report, ReportStatus::Resolved, $admin, $note, 'ban');

        return true;
    }

    /** Looked at it, nothing to do. */
    public function dismiss(Report $report, User $admin, string $note): void
    {
        $this->record($report, ReportStatus::Dismissed, $admin, 'dismiss');
        $this->audit($report, ReportStatus::Dismissed, $admin, $note, 'dismiss');
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
        // Fail closed: `close()` writes the PRIMARY report's status onto each
        // sibling, so calling this while the primary is still open would stamp
        // a resolver and a timestamp onto rows whose status stays `open` —
        // reports that sit in the queue forever looking decided.
        if (! $report->status->isTerminal()) {
            return 0;
        }

        $siblings = Report::query()->againstSameTarget($report)->open()->get();

        foreach ($siblings as $sibling) {
            $this->record($sibling, $report->status, $admin, 'sibling_of:'.$report->id);
            $this->audit($sibling, $report->status, $admin, $note, 'sibling_of:'.$report->id);
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

    /** The database half — must commit with whatever it is recording. */
    private function record(Report $report, ReportStatus $status, User $admin, string $action): void
    {
        $report->resolve($status, $admin);
    }

    /**
     * The audit trail, written AFTER the commit.
     *
     * `note` is required by the Filament action, so this always carries a
     * human's stated reason — the thing a takedown dispute or a store review
     * actually asks for. Deliberately outside the transaction: a rolled-back
     * action must not leave a log line saying it happened.
     */
    private function audit(Report $report, ReportStatus $status, User $admin, string $note, string $action): void
    {
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
