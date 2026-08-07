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
        $counts = [];

        DB::transaction(function () use ($user, &$counts): void {
            $counts['shares'] = $this->deleteUnpublishedShares($user);
            $counts['identity'] = $this->releaseInfluencerIdentity($user);
            $counts = array_merge($counts, $this->deletePersonalRows($user));
            $this->anonymiseRetainedRows($user);
            $this->scrubUserRow($user);
        });

        // Storage last and outside the transaction: an object delete cannot be
        // rolled back, so doing it inside would leave a file gone for a purge
        // the database then undid.
        $this->deleteAvatar($user);

        // Scout holds a copy of username/name/bio outside Postgres. Without
        // this the person stays findable in people-search after the purge —
        // the one place the erasure would visibly not have happened.
        $user->unsearchable();

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
     */
    private function deleteUnpublishedShares(User $user): int
    {
        $shares = Share::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', ShareStatus::Published)
            // Belt and braces: a share can carry a published place_source even
            // if its own status later moved. Never delete one that published.
            ->whereNull('published_place_source_id')
            ->get(['id', 'source_post_id']);

        if ($shares->isEmpty()) {
            return 0;
        }

        $sourcePostIds = $shares->pluck('source_post_id')->unique();

        Share::query()->whereIn('id', $shares->pluck('id'))->delete();

        // A source_post is keyed on (platform, external_id) and is therefore
        // SHARED — two users posting the same reel resolve to one row. Only
        // remove it, and the media hanging off it, when this user's share was
        // the last thing referencing it.
        $orphaned = $sourcePostIds->reject(
            fn ($id) => Share::query()->where('source_post_id', $id)->exists()
                || DB::table('place_sources')->where('source_post_id', $id)->exists()
        );

        if ($orphaned->isNotEmpty()) {
            $orphanedIds = $orphaned->values()->all();
            $this->deleteMediaObjects($orphanedIds);
            SourcePost::query()->whereIn('id', $orphanedIds)->delete();
        }

        return $shares->count();
    }

    /**
     * Remove the stored files behind media_assets before the rows cascade away,
     * so the purge does not leak orphaned objects nobody has a handle to.
     *
     * @param  list<int>  $sourcePostIds
     */
    private function deleteMediaObjects(array $sourcePostIds): void
    {
        $assets = DB::table('media_assets')
            ->whereIn('source_post_id', $sourcePostIds)
            ->get(['id', 'disk', 'storage_path', 'sha256']);

        foreach ($assets as $asset) {
            // The same file can back several source_posts (same sha256, e.g. a
            // repost). Deleting the object while another live row still points
            // at it would break a place that is staying.
            $sharedElsewhere = DB::table('media_assets')
                ->where('sha256', $asset->sha256)
                ->whereNotIn('source_post_id', $sourcePostIds)
                ->exists();

            if ($sharedElsewhere) {
                continue;
            }

            try {
                Storage::disk($asset->disk)->delete($asset->storage_path);
            } catch (\Throwable $e) {
                // Never fail a legally-required purge because a bucket blinked.
                // The row still goes; the object is swept by the retention pass.
                Log::warning('gdpr.purge.media_delete_failed', [
                    'media_asset_id' => $asset->id,
                    'reason' => $e->getMessage(),
                ]);
            }
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

        return [
            // OAuth tokens for their linked platform accounts — the single most
            // sensitive thing we hold about them, and live credentials at that.
            'platform_accounts' => DB::table('platform_accounts')->where('user_id', $id)->delete(),
            'devices' => DB::table('devices')->where('user_id', $id)->delete(),
            'tokens' => DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)->where('tokenable_id', $id)->delete(),
            'sessions' => DB::table('sessions')->where('user_id', $id)->delete(),
            'notifications' => DB::table('notifications')
                ->where('notifiable_type', User::class)->where('notifiable_id', $id)->delete(),
            // Both directions: who they followed AND who followed them. The
            // second is somebody else's edge, but it names this user, and a
            // follower list is exactly the "who do you know" graph GDPR means.
            'follows' => DB::table('follows')->where('follower_user_id', $id)->delete()
                + DB::table('follows')->where('followee_type', User::class)->where('followee_id', $id)->delete(),
            'reviews' => DB::table('reviews')->where('user_id', $id)->delete(),
            'review_reports' => DB::table('review_reports')->where('user_id', $id)->delete(),
            'lists' => DB::table('place_lists')->where('user_id', $id)->delete(),
            'place_tags' => DB::table('user_place_tags')->where('user_id', $id)->delete(),
            'hidden_places' => DB::table('hidden_places')->where('user_id', $id)->delete(),
            'feed_dismissals' => DB::table('feed_dismissals')->where('user_id', $id)->delete(),
            'invitations' => DB::table('invitations')->where('inviter_user_id', $id)->delete(),
            'influencer_claims' => DB::table('influencer_claims')->where('user_id', $id)->delete(),
            'place_claims' => DB::table('place_claims')->where('user_id', $id)->delete(),
            // Keyed on the email, not the id — and the email is about to change.
            'verification_codes' => DB::table('email_verification_codes')->where('email', $user->email)->delete(),
            'password_resets' => DB::table('password_reset_tokens')->where('email', $user->email)->delete(),
        ];
    }

    /**
     * Rows that stay because they are somebody else's record too, but whose
     * pointer at this user has to be cut rather than left dangling at a
     * scrubbed identity.
     */
    private function anonymiseRetainedRows(User $user): void
    {
        $id = $user->id;

        // Suggested edits to a business: the edit is the business's record now,
        // the suggester is not part of it.
        DB::table('place_edits')->where('user_id', $id)->update(['user_id' => null]);

        // Moderation history: keep WHAT was decided, drop WHO decided it.
        DB::table('place_merges')->where('performed_by_user_id', $id)->update(['performed_by_user_id' => null]);
        DB::table('place_claims')->where('reviewed_by_user_id', $id)->update(['reviewed_by_user_id' => null]);
        DB::table('influencer_claims')->where('reviewed_by_user_id', $id)->update(['reviewed_by_user_id' => null]);

        // A code THIS user scanned as venue staff: the redemption is the
        // restaurant's billing record, the scanner's identity is not.
        DB::table('redemptions')->where('redeemed_by_user_id', $id)->update(['redeemed_by_user_id' => null]);
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
            if (! $this->hasUnsettledPayout($user)) {
                DB::table('users')->where('id', $user->id)->update([
                    'stripe_connect_account_id' => null,
                    'stripe_connect_onboarded_at' => null,
                ]);
            }

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
