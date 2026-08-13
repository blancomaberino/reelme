<?php

namespace App\Services\Gdpr;

use App\Enums\PayoutStatus;
use App\Enums\ShareStatus;
use App\Models\Influencer;
use App\Models\Payout;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Erases a user (T-050, NFR-10) — the irreversible half of {@see AccountDeletion}.
 *
 * The shape of this is dictated by one constraint that overrides the instinct
 * to `DELETE FROM users`: **the row must survive.** `ledger_entries`,
 * `redemptions` and `payouts` are an append-only financial record we are
 * legally required to keep (ADR-009), and they anchor on `users.id`. Dropping
 * that anchor does not anonymise the books, it corrupts them — and the nightly
 * balance check would start failing for a reason nobody could reconstruct.
 *
 * So erasure here means: hard-delete everything that is purely personal, keep
 * the community's data with its attribution pointed at a row that no longer
 * says who anyone was, and scrub that row until it identifies nobody.
 *
 * Idempotent by construction — every step is a delete-if-present or a write of
 * a fixed value, so a retry after a mid-way failure finishes the job rather
 * than doubling anything.
 */
class UserDataPurger
{
    /** What a purged account is called wherever its name still has to render. */
    public const DELETED_NAME = 'Deleted user';

    /** Reserved, non-deliverable domain for scrubbed addresses (RFC 6761 `.invalid`). */
    public const SCRUBBED_EMAIL_DOMAIN = '@reelmap.invalid';

    /**
     * @return array<string, int> per-surface counts, for the log line
     */
    public function purge(User $user): array
    {
        // The in-memory instance may be stale — `scrubUserRow()` writes through
        // the query builder, so a second purge() on the SAME object would not
        // see the first one's work and would mint a second set of identifiers.
        $user->refresh();

        $counts = [];
        /** @var list<object> $doomedObjects */
        $doomedObjects = [];

        DB::transaction(function () use ($user, &$counts, &$doomedObjects): void {
            [$shares, $doomedObjects] = $this->deleteUnpublishedShares($user);
            $counts['shares'] = $shares;
            $counts['identity'] = $this->releaseInfluencerIdentity($user);
            $counts = array_merge($counts, $this->deletePersonalRows($user));
            // Strictly before `anonymiseRetainedRows()`, which nulls `user_id`
            // on this very table and would leave these rows unfindable.
            // Named for what the number IS: rows deleted. The notes cleared on
            // the rows that survive are not counted, and a key called
            // `suggestion_notes` would read as though they were.
            $counts['note_only_suggestions'] = $this->purgeSuggestionNotes($user);
            $this->anonymiseRetainedRows($user);
            $this->scrubUserRow($user);
        });

        // Storage strictly AFTER the commit. An object delete cannot be rolled
        // back, so a media delete inside the transaction would leave files gone
        // for a purge the database then undid — a live row pointing at a 404,
        // which no later pass can detect. Collect inside, delete outside.
        $this->deleteObjects($doomedObjects);
        $this->deleteAvatar($user);
        $this->deleteExportArchives($user);

        // Scout holds a copy of username/name/bio outside Postgres. Without
        // this the person stays findable in people-search after the purge —
        // the one place the erasure would visibly not have happened.
        //
        // Guarded like every other external call here: the database work is
        // already committed by now, and a Meilisearch outage must not turn a
        // completed erasure into a failed job that never logs and never runs
        // the deferred Stripe pass.
        try {
            $user->unsearchable();
        } catch (\Throwable $e) {
            Log::warning('gdpr.purge.unsearchable_failed', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);
        }

        Log::info('gdpr.purge.completed', ['user_id' => $user->id] + $counts);

        return $counts;
    }

    /**
     * Shares that never reached the map are the user's own drafts and failures —
     * nobody else's data hangs off them, so they go entirely, taking their
     * analysis runs, stage metrics and corrections by FK cascade.
     *
     * Published shares stay: they are what put a place on the map, and a place
     * with no source is a claim with no provenance. Their attribution is
     * anonymised instead (see {@see scrubUserRow()} — the FK keeps pointing at
     * a row that no longer names anybody).
     *
     * @return array{0: int, 1: list<object>} deleted count, and the storage
     *                                        objects the caller must remove
     *                                        after the transaction commits
     */
    private function deleteUnpublishedShares(User $user): array
    {
        $shares = Share::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', ShareStatus::Published)
            // Belt and braces: a share can carry a published place_source even
            // if its own status later moved. Never delete one that published.
            ->whereNull('published_place_source_id')
            ->get(['id', 'source_post_id']);

        if ($shares->isEmpty()) {
            return [0, []];
        }

        $sourcePostIds = $shares->pluck('source_post_id')->unique();

        // Chunked: Postgres binds one parameter per id and caps at 65,535, and
        // a prolific sharer's draft pile is exactly the case this has to survive.
        foreach ($shares->pluck('id')->chunk(5_000) as $chunk) {
            Share::query()->whereIn('id', $chunk)->delete();
        }

        // A source_post is keyed on (platform, external_id) and is therefore
        // SHARED — two users posting the same reel resolve to one row. Only
        // remove it, and the media hanging off it, when this user's share was
        // the last thing referencing it.
        //
        // Two set-based queries rather than two exists() per post: this runs
        // inside the purge transaction, and an N+1 over a few thousand drafts
        // is how a 300s job timeout turns into a rollback.
        $stillReferenced = Share::query()
            ->whereIn('source_post_id', $sourcePostIds)
            ->distinct()
            ->pluck('source_post_id')
            ->merge(
                DB::table('place_sources')
                    ->whereIn('source_post_id', $sourcePostIds)
                    ->distinct()
                    ->pluck('source_post_id')
            );

        $orphaned = $sourcePostIds->diff($stillReferenced)->values();

        if ($orphaned->isEmpty()) {
            return [$shares->count(), []];
        }

        $orphanedIds = $orphaned->all();

        // Read the objects now — the rows are about to cascade away with their
        // source_posts, and afterwards there is no handle on the files at all.
        $doomed = $this->orphanedObjects($orphanedIds);

        SourcePost::query()->whereIn('id', $orphanedIds)->delete();

        return [$shares->count(), $doomed];
    }

    /**
     * The stored files behind soon-to-be-deleted media_assets rows, minus any
     * whose object something else still points at.
     *
     * @param  list<int>  $sourcePostIds
     * @return list<object>
     */
    private function orphanedObjects(array $sourcePostIds): array
    {
        $assets = DB::table('media_assets')
            ->whereIn('source_post_id', $sourcePostIds)
            ->get(['id', 'disk', 'storage_path']);

        if ($assets->isEmpty()) {
            return [];
        }

        // One set-based query for "which of these paths does something OUTSIDE
        // the doomed set still use", rather than an exists() per asset.
        //
        // Keyed on the PATH, not on sha256. `MediaPaths::original()` embeds the
        // share id, so two rows sharing a hash live at different keys — a
        // sha256 guard would skip a delete for an object nothing else points
        // at, drop the row, and leak the file with no handle left on it.
        $keptPaths = DB::table('media_assets')
            ->whereIn('storage_path', $assets->pluck('storage_path')->unique())
            ->whereNotIn('source_post_id', $sourcePostIds)
            ->pluck('storage_path')
            ->flip();

        return $assets
            ->reject(fn ($asset) => $keptPaths->has($asset->storage_path))
            ->values()
            ->all();
    }

    /**
     * @param  list<object>  $objects
     */
    private function deleteObjects(array $objects): void
    {
        foreach ($objects as $object) {
            try {
                Storage::disk($object->disk)->delete($object->storage_path);
            } catch (\Throwable $e) {
                // Never fail a legally-required purge because a bucket blinked.
                // The row is already gone; the object is swept by the retention
                // pass, which is exactly what that pass is for.
                Log::warning('gdpr.purge.media_delete_failed', [
                    'media_asset_id' => $object->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * A finished export is the single densest file of this person's data we
     * ever produce, and `PruneDataExports` would otherwise leave it sitting on
     * the disk for up to a week AFTER the irreversible erasure completed.
     */
    private function deleteExportArchives(User $user): void
    {
        try {
            Storage::disk((string) config('media.disk'))->deleteDirectory("exports/{$user->id}");
        } catch (\Throwable $e) {
            Log::warning('gdpr.purge.exports_delete_failed', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * An `influencers` row is public business data about a creator's account,
     * not the private data of whoever claimed it (R-06 lawful basis). The
     * identity stays; the *link* to this person is what has to go.
     */
    private function releaseInfluencerIdentity(User $user): int
    {
        return Influencer::query()
            ->where('claimed_by_user_id', $user->id)
            ->update(['claimed_by_user_id' => null]);
    }

    /**
     * Everything that is only ever about this one person. No community data
     * hangs off any of it, so it is deleted outright rather than anonymised.
     *
     * @return array<string, int>
     */
    private function deletePersonalRows(User $user): array
    {
        $id = $user->id;

        // `Relation::enforceMorphMap` is in force (AppServiceProvider), so every
        // morph column on disk holds the ALIAS — `user`, not the FQCN. Querying
        // these with `User::class` matches nothing at all, silently: the purge
        // reports success having deleted zero notifications, zero inbound
        // follows and zero tokens. Never hard-code either value here.
        $morph = $user->getMorphClass();

        // Denormalised follower/following counters are maintained by hand on the
        // follow/unfollow paths. Bulk-deleting the edges underneath them would
        // leave every counterparty permanently inflated — visible on OTHER
        // people's profiles, with nothing that ever recomputes it.
        $this->decrementFollowCounters($user, $morph);

        return [
            // OAuth tokens for their linked platform accounts — the single most
            // sensitive thing we hold about them, and live credentials at that.
            'platform_accounts' => DB::table('platform_accounts')->where('user_id', $id)->delete(),
            'devices' => DB::table('devices')->where('user_id', $id)->delete(),
            'tokens' => DB::table('personal_access_tokens')
                ->where('tokenable_type', $morph)->where('tokenable_id', $id)->delete(),
            'sessions' => DB::table('sessions')->where('user_id', $id)->delete(),
            'notifications' => DB::table('notifications')
                ->where('notifiable_type', $morph)->where('notifiable_id', $id)->delete(),
            // Both directions: who they followed AND who followed them. The
            // second is somebody else's edge, but it names this user, and a
            // follower list is exactly the "who do you know" graph GDPR means.
            'follows' => DB::table('follows')->where('follower_user_id', $id)->delete()
                + DB::table('follows')->where('followee_type', $morph)->where('followee_id', $id)->delete(),
            'reviews' => DB::table('reviews')->where('user_id', $id)->delete(),
            'review_reports' => DB::table('review_reports')->where('user_id', $id)->delete(),
            // Moderation flags (T-049), BOTH directions. `details` is 2000
            // characters of free prose — exactly where PII lands — and a report
            // naming this person as its target keeps identifying them after the
            // erasure. The FK cascade never fires, because the user row is
            // anonymised rather than deleted.
            'reports' => DB::table('reports')->where('reporter_user_id', $id)->delete()
                + DB::table('reports')->where('reportable_type', $morph)->where('reportable_id', $id)->delete(),
            'lists' => DB::table('place_lists')->where('user_id', $id)->delete(),
            'place_tags' => DB::table('user_place_tags')->where('user_id', $id)->delete(),
            'hidden_places' => DB::table('hidden_places')->where('user_id', $id)->delete(),
            'feed_dismissals' => DB::table('feed_dismissals')->where('user_id', $id)->delete(),
            // Both the invitations they SENT and any addressed TO them —
            // `invitations.email` holds a raw address, and theirs is about to
            // stop existing everywhere else.
            'invitations' => DB::table('invitations')->where('inviter_user_id', $id)->delete()
                + DB::table('invitations')->where('email', $user->email)->delete(),
            'influencer_claims' => DB::table('influencer_claims')->where('user_id', $id)->delete(),
            'place_claims' => DB::table('place_claims')->where('user_id', $id)->delete(),
            // Keyed on the email, not the id — and the email is about to change.
            'verification_codes' => DB::table('email_verification_codes')->where('email', $user->email)->delete(),
            'password_resets' => DB::table('password_reset_tokens')->where('email', $user->email)->delete(),
        ];
    }

    /**
     * Put the denormalised follow counters back before the edges disappear.
     *
     * `users.followers_count` / `following_count` and `influencers.followers_count`
     * are maintained by hand on the follow/unfollow paths, so a bulk delete
     * under them leaves everyone this person followed with an inflated follower
     * count, and everyone who followed them with an inflated following count.
     * Nothing recomputes those — the drift is permanent, and it shows on OTHER
     * people's profiles, which is where it would eventually be noticed and be
     * very hard to explain.
     */
    private function decrementFollowCounters(User $user, string $morph): void
    {
        $outbound = DB::table('follows')
            ->where('follower_user_id', $user->id)
            ->get(['followee_type', 'followee_id']);

        foreach ($outbound->groupBy('followee_type') as $type => $rows) {
            $model = $type === (new Influencer)->getMorphClass() ? Influencer::class : User::class;

            $model::query()
                ->whereIn('id', $rows->pluck('followee_id'))
                // Guarded: a counter already at zero must not go negative, and a
                // re-run of the purge must not decrement a second time.
                ->where('followers_count', '>', 0)
                ->decrement('followers_count');
        }

        $inboundFollowers = DB::table('follows')
            ->where('followee_type', $morph)
            ->where('followee_id', $user->id)
            ->pluck('follower_user_id');

        if ($inboundFollowers->isNotEmpty()) {
            User::query()
                ->whereIn('id', $inboundFollowers)
                ->where('following_count', '>', 0)
                ->decrement('following_count');
        }
    }

    /**
     * Rows that stay because they are somebody else's record too, but whose
     * pointer at this user has to be cut rather than left dangling at a
     * scrubbed identity.
     *
     * `offers.created_by_user_id` is deliberately absent: an offer is the
     * venue's business record, and the column anchors the scrubbed row exactly
     * as `shares.user_id` and `redemptions.user_id` do. Nothing identifying
     * survives on the other end of it.
     */
    private function anonymiseRetainedRows(User $user): void
    {
        $id = $user->id;

        // Suggested edits to a business: the edit is the business's record now,
        // the suggester is not part of it.
        DB::table('place_edits')->where('user_id', $id)->update(['user_id' => null]);
        // Same rule for the PROPOSALS (T-083), both ends of one. A pending row
        // is still a correction a venue needs, and an approved one is already
        // part of that venue's history — what has to go is the name attached.
        // Note the order against the partial unique index on
        // (place_id, user_id) WHERE status = 'pending': nulling the column can
        // never collide, because NULLs are distinct in a Postgres unique index.
        //
        // The FREE-TEXT note is a different question, answered by
        // {@see purgeSuggestionNotes()} — which the caller runs BEFORE this
        // method, while these rows can still be found by `user_id`.
        DB::table('place_edit_suggestions')->where('user_id', $id)->update(['user_id' => null]);
        DB::table('place_edit_suggestions')->where('reviewed_by_user_id', $id)->update(['reviewed_by_user_id' => null]);

        // Moderation history: keep WHAT was decided, drop WHO decided it.
        DB::table('place_merges')->where('performed_by_user_id', $id)->update(['performed_by_user_id' => null]);
        DB::table('place_claims')->where('reviewed_by_user_id', $id)->update(['reviewed_by_user_id' => null]);
        DB::table('influencer_claims')->where('reviewed_by_user_id', $id)->update(['reviewed_by_user_id' => null]);
        // A purged ADMIN otherwise stays linked to every report they closed and
        // every notice they actioned. Same rule as the rest of this method: the
        // decision is the record we keep, the decider is not.
        DB::table('reports')->where('resolved_by_user_id', $id)->update(['resolved_by_user_id' => null]);
        DB::table('takedown_requests')->where('actioned_by_user_id', $id)->update(['actioned_by_user_id' => null]);

        // A code THIS user scanned as venue staff: the redemption is the
        // restaurant's billing record, the scanner's identity is not.
        DB::table('redemptions')->where('redeemed_by_user_id', $id)->update(['redeemed_by_user_id' => null]);
    }

    /**
     * The free prose on a suggested edit (T-112) — the one part of that table
     * anonymising is not enough for.
     *
     * T-083 keeps suggestion rows and nulls `user_id`, on the reasoning that a
     * field patch is the business's record rather than the submitter's. A NOTE
     * breaks that reasoning: it is 2000 characters in a person's own words,
     * which is exactly where PII lands, and it is precisely why `reports` are
     * deleted outright a few lines up rather than anonymised. An unattributed
     * paragraph saying "my sister worked here until March" still identifies
     * people.
     *
     * So the split is on what the row would be worth WITHOUT the prose:
     *
     * - **note-only rows are deleted.** There is nothing else in them — no
     *   patch, nothing the venue could act on once the words are gone. Keeping
     *   an empty-diff row with a null author is keeping a record of nobody
     *   saying nothing.
     * - **every other row keeps its patch and loses its note.** The field diff
     *   is the venue's record and an approved one is already part of its
     *   history, so the row survives with `changes` intact and `note` cleared.
     *
     * Deliberately NOT restricted to pending/rejected rows: an approved or
     * actioned row's note is the same prose by the same person, and "we already
     * acted on it" is not a lawful basis for keeping their words. What survives
     * is what was DONE — the diff, the `place_edits` row and the reviewer's own
     * `reason`, which is the moderator's writing and not this user's.
     *
     * Note-only is asked of the COLUMN here, not of
     * {@see PlaceEditSuggestion::isNoteOnly()}, which additionally treats a row
     * whose only field is off the allow-list as note-only. The looser SQL keeps
     * such a row instead of deleting it — and clears its note either way, so
     * nothing this person wrote survives on either side of the split.
     *
     * @return int rows deleted, for the purge log line
     */
    private function purgeSuggestionNotes(User $user): int
    {
        $deleted = DB::table('place_edit_suggestions')
            ->where('user_id', $user->id)
            ->whereNotNull('note')
            // BOTH empty shapes, and the list one is not hypothetical: the
            // column is written through Eloquent's `array` cast, and PHP encodes
            // an empty array as `[]`, never `{}`. Every note-only row in the
            // table is therefore `[]` today — matching only `{}` deleted nothing
            // at all, which is a purge that reports success having kept the
            // prose. Compared as jsonb rather than as text, because Postgres
            // normalises jsonb on write and a string comparison would turn on
            // whitespace the column does not preserve.
            ->whereRaw("changes IN ('{}'::jsonb, '[]'::jsonb)")
            ->delete();

        DB::table('place_edit_suggestions')
            ->where('user_id', $user->id)
            ->whereNotNull('note')
            ->update(['note' => null]);

        return $deleted;
    }

    /**
     * Make the surviving row identify nobody.
     *
     * `email`/`username` are uniquely indexed, so they cannot simply be nulled
     * or set to a shared literal — a second deletion would collide with the
     * first. Each gets its own ULID-suffixed value on a reserved,
     * non-deliverable domain.
     */
    private function scrubUserRow(User $user): void
    {
        // Already scrubbed — a re-run (the deferred Stripe pass, or a manual
        // retry after a partial failure) must not mint a SECOND set of random
        // identifiers. Churning them would break any log line or support ticket
        // that recorded the first, for no benefit.
        if (Str::endsWith((string) $user->email, self::SCRUBBED_EMAIL_DOMAIN)) {
            $attributes = ['purged_at' => $user->purged_at ?? now()];

            if (! $this->hasUnsettledPayout($user)) {
                $attributes['stripe_connect_account_id'] = null;
                $attributes['stripe_connect_onboarded_at'] = null;
            }

            DB::table('users')->where('id', $user->id)->update($attributes);

            return;
        }

        $token = strtolower((string) Str::ulid());

        $attributes = [
            // NOT NULL in the schema, and it is what renders beside a published
            // share this person contributed. A literal is right here: it names
            // nobody, and it reads as an explanation rather than as missing data.
            'name' => self::DELETED_NAME,
            'username' => "deleted_{$token}",
            'email' => "deleted_{$token}".self::SCRUBBED_EMAIL_DOMAIN,
            // Not null: a null password is the "social sign-in only" state, and
            // that account CAN still be signed into by other means. An
            // unguessable hash is the state where nothing opens it.
            'password' => bcrypt((string) Str::random(64)),
            'bio' => null,
            'avatar_path' => null,
            'birthdate' => null,
            'favorite_topics' => null,
            'favorite_foods' => null,
            // Self-declared and publicly displayed — the same class of personal
            // data as `bio`, so erasure has to reach it too (T-110).
            'country_code' => null,
            'preferred_analysis_model' => null,
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_ts' => null,
            'email_verified_at' => null,
            'is_influencer' => false,
            'is_restaurant_owner' => false,
            'is_admin' => false,
            'is_public' => false,
            'locale' => User::DEFAULT_LOCALE,
            // The size of an erased account's social graph is still a fact
            // about them, and the edges behind these are gone either way.
            'followers_count' => 0,
            'following_count' => 0,
            'updated_at' => now(),
            // The completion marker. `deletion_requested_at` stays set — it is
            // the record of WHY this row is soft-deleted — so without a
            // separate "done" fact the hourly sweep would match every account
            // ever purged, on every run, forever.
            'purged_at' => now(),
        ];

        // The Stripe account id is the one field that may have to outlive the
        // purge for a while: money still in flight has to land somewhere, and
        // the connected account is how it gets there. Kept only while a payout
        // is genuinely unsettled — the caller re-runs the purge later to
        // finish the job (see PurgeUserData).
        if (! $this->hasUnsettledPayout($user)) {
            $attributes['stripe_connect_account_id'] = null;
            $attributes['stripe_connect_onboarded_at'] = null;
        }

        // Bypasses fillable/casts on purpose: several of these are guarded
        // precisely because a request must never set them, which is not a
        // reason a purge cannot.
        DB::table('users')->where('id', $user->id)->update($attributes);
    }

    /** Is money still moving toward this account? */
    public function hasUnsettledPayout(User $user): bool
    {
        return Payout::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Processing])
            ->exists();
    }

    private function deleteAvatar(User $user): void
    {
        $path = $user->getOriginal('avatar_path');

        // Remote avatars (an OAuth provider's CDN) are not ours to delete, and
        // `delete()` on a path that starts with a scheme would be nonsense.
        if (! is_string($path) || $path === '' || Str::startsWith($path, ['http://', 'https://'])) {
            return;
        }

        try {
            Storage::disk((string) config('media.disk'))->delete($path);
        } catch (\Throwable $e) {
            Log::warning('gdpr.purge.avatar_delete_failed', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
