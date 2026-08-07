<?php

namespace App\Services\Gdpr;

use App\Jobs\Gdpr\PurgeUserData;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The two-phase account deletion T-050 / NFR-10 describes.
 *
 * Phase one is synchronous and total from the user's side: the account is
 * soft-deleted and every token dies in the same request, so the tap that says
 * "delete my account" really does end the session everywhere, immediately.
 *
 * Phase two — the irreversible erasure — waits out `gdpr.purge_grace_days`.
 * Erasure cannot be undone and the request is one tap away from a bad evening,
 * so signing back in inside the window cancels it. That is the entire reason
 * the account is SOFT-deleted rather than scrubbed on the spot: the row has to
 * survive long enough to be restorable.
 *
 * Not to be confused with the purge itself ({@see UserDataPurger}) — this class
 * only decides when it happens.
 */
class AccountDeletion
{
    /**
     * Begin deletion. Idempotent: asking twice does not shorten the grace
     * period or queue a second purge for the same account.
     */
    public function request(User $user): void
    {
        if ($user->trashed()) {
            return;
        }

        // Tokens first. If anything below throws, the credentials are already
        // dead — the failure mode is "an account that has to be re-deleted",
        // never "a session that outlived the deletion request".
        $user->tokens()->delete();
        $user->forceFill(['deletion_requested_at' => now()])->saveQuietly();
        $user->delete();

        PurgeUserData::dispatch($user->id)->delay($this->purgeAt($user));

        Log::info('gdpr.deletion.requested', [
            'user_id' => $user->id,
            'purge_at' => $this->purgeAt($user)->toIso8601String(),
        ]);
    }

    /**
     * Undo a pending deletion — the "I changed my mind" path, reached by
     * signing in again inside the grace period.
     *
     * Returns false when there is nothing to cancel, and CRUCIALLY when the
     * grace period has already lapsed: past that point the purge is either
     * running or done, and restoring the row would resurrect a shell of an
     * account whose data is gone.
     */
    public function cancel(User $user): bool
    {
        if (! $this->isPending($user) || ! $this->isWithinGrace($user)) {
            return false;
        }

        $user->forceFill(['deletion_requested_at' => null])->saveQuietly();
        $user->restore();

        Log::info('gdpr.deletion.cancelled', ['user_id' => $user->id]);

        return true;
    }

    /**
     * Is this soft delete the USER's doing, rather than an admin ban?
     *
     * Both states share `deleted_at`, and conflating them is the failure that
     * matters: a banned account reaching the grace-period logic would be able
     * to un-ban itself by signing in, which is the one thing a ban has to
     * survive. `deletion_requested_at` is the only thing that tells them apart.
     */
    public function isPending(User $user): bool
    {
        return $user->trashed() && $user->deletion_requested_at !== null;
    }

    /**
     * Can this account still be signed back into and restored?
     *
     * The queued purge holds the same clock (it re-checks on execution), so the
     * two answers agree even if the job runs late.
     */
    public function isWithinGrace(User $user): bool
    {
        return $user->deletion_requested_at !== null && $this->purgeAt($user)->isFuture();
    }

    /** When the purge for this account becomes due. */
    public function purgeAt(User $user): Carbon
    {
        // Rebuilt through Carbon::parse rather than ->copy(): the model's
        // timestamps are typed as the base Carbon\Carbon, and returning that
        // where callers expect Illuminate's subclass is exactly the kind of
        // near-miss PHPStan is for.
        $from = Carbon::parse($user->deletion_requested_at ?? now());

        return $from->addDays((int) config('gdpr.purge_grace_days'));
    }
}
