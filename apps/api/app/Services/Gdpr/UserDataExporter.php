<?php

namespace App\Services\Gdpr;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * The data-portability half of NFR-10 (T-050): everything we hold about one
 * person, in a form they can actually read and re-use.
 *
 * One JSON file per entity rather than one giant document — GDPR Art. 20 asks
 * for "structured, commonly used and machine-readable", and a flat set of named
 * files is what a person can open, diff, or feed to something else without
 * writing a parser first.
 *
 * The hard rule running through every collector below: **an export may contain
 * other people's data only where it is already public to this user.** Their
 * followers are names they can see in the app; the email addresses behind those
 * names are not theirs to receive. Getting this backwards turns a
 * privacy feature into a disclosure.
 */
class UserDataExporter
{
    public function __construct(private readonly AccountDeletion $deletion) {}

    /**
     * Build the archive and return its path on the private media disk.
     */
    public function export(User $user): string
    {
        $sections = $this->collect($user);

        // ZipArchive needs a real filesystem path, and the media disk may be
        // R2 — so build in the local temp dir and stream the finished file up.
        // tempnam() atomically CREATES the file it names. Appending '.zip' to
        // the result would point at a different, uncreated path — leaking a
        // zero-byte file per export and writing to a name nothing reserved.
        $tmp = tempnam(sys_get_temp_dir(), 'reelmap-export-');

        if ($tmp === false) {
            throw new \RuntimeException('Could not allocate a temp file for the export.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new \RuntimeException('Could not open the export archive for writing.');
        }

        // Everything from here is wrapped, because two of the steps below can
        // throw: JSON_THROW_ON_ERROR on a bad byte, and the upload. Without the
        // finally, each failure leaks a temp file AND an open ZipArchive
        // handle — and the job retries, so the leak repeats.
        $path = $this->pathFor($user);
        $stream = null;
        $closed = false;

        try {
            foreach ($sections as $name => $rows) {
                // THROW_ON_ERROR, because the alternative is silent:
                // json_encode returns false on one invalid UTF-8 byte (captions
                // come from scraped third-party content), the (string) cast
                // turns that into '', and the user is handed an empty file and
                // told it worked.
                $zip->addFromString("{$name}.json", (string) json_encode(
                    $rows,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ));
            }

            $zip->addFromString('README.txt', $this->readme($user));

            if (! $zip->close()) {
                throw new \RuntimeException('The export archive could not be finalised.');
            }

            $closed = true;

            $stream = fopen($tmp, 'rb');

            // `put()` returns FALSE on a failed write unless the disk sets
            // `throw`. Ignoring it is the worst outcome this class has: the job
            // logs success and mails a signed link to an object that does not
            // exist, and nothing anywhere says otherwise.
            if (Storage::disk($this->disk())->put($path, $stream) === false) {
                throw new \RuntimeException('The export archive could not be uploaded.');
            }
        } finally {
            // Only if it is still open: close() on a closed archive raises a
            // ValueError, and `@` suppresses warnings, not exceptions.
            if (! $closed) {
                try {
                    $zip->close();
                } catch (\Throwable) {
                    // Already unusable — nothing left to release.
                }
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($tmp);
        }

        return $path;
    }

    /** A signed, expiring link to a finished archive. */
    public function downloadUrl(string $path): string
    {
        return Storage::disk($this->disk())->temporaryUrl(
            $path,
            now()->addHours((int) config('gdpr.export_url_ttl_hours')),
        );
    }

    /**
     * @return array<string, mixed> section name => rows
     */
    public function collect(User $user): array
    {
        return [
            'profile' => $this->profile($user),
            'platform_accounts' => $this->platformAccounts($user),
            'shares' => $this->shares($user),
            'places' => $this->places($user),
            'lists' => $this->lists($user),
            'place_tags' => $this->placeTags($user),
            'reviews' => $this->reviews($user),
            'reports' => $this->reports($user),
            'follows' => $this->follows($user),
            'notifications' => $this->notifications($user),
            'devices' => $this->devices($user),
            'redemptions' => $this->redemptions($user),
            'ledger_entries' => $this->ledgerEntries($user),
            'payouts' => $this->payouts($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'bio' => $user->bio,
            'avatar_path' => $user->avatar_path,
            'birthdate' => $user->birthdate?->toDateString(),
            'favorite_topics' => $user->favorite_topics,
            'favorite_foods' => $user->favorite_foods,
            'locale' => $user->locale,
            'country_code' => $user->country_code,
            'is_public' => $user->is_public,
            'is_influencer' => $user->is_influencer,
            'is_restaurant_owner' => $user->is_restaurant_owner,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'created_at' => $user->created_at?->toIso8601String(),
            'deletion_requested_at' => $user->deletion_requested_at?->toIso8601String(),
            'deletion_scheduled_for' => $user->deletion_requested_at !== null
                ? $this->deletion->purgeAt($user)->toIso8601String()
                : null,
        ];
    }

    /**
     * Linked accounts, WITHOUT tokens (NFR-9).
     *
     * An access token is a live credential, not a fact about the user — putting
     * one in a downloadable archive would hand out working platform access in
     * the name of transparency.
     *
     * @return list<array<string, mixed>>
     */
    private function platformAccounts(User $user): array
    {
        return DB::table('platform_accounts')
            ->where('user_id', $user->id)
            ->get(['id', 'platform', 'external_user_id', 'handle', 'scopes', 'token_expires_at', 'last_synced_at', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shares(User $user): array
    {
        return DB::table('shares')
            ->leftJoin('source_posts', 'shares.source_post_id', '=', 'source_posts.id')
            ->where('shares.user_id', $user->id)
            ->orderBy('shares.id')
            ->get([
                'shares.id', 'shares.status', 'shares.shared_via', 'shares.created_at',
                'shares.published_at', 'shares.failure_reason',
                'shares.corrected_extraction_json',
                'source_posts.url as source_url', 'source_posts.platform', 'source_posts.caption',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Places this user put on the map — the community-facing result of their
     * shares, which stays behind after a deletion, so it belongs in the copy
     * they take with them.
     *
     * @return list<array<string, mixed>>
     */
    private function places(User $user): array
    {
        return DB::table('place_sources')
            ->join('places', 'place_sources.place_id', '=', 'places.id')
            ->join('shares', 'place_sources.share_id', '=', 'shares.id')
            ->where('shares.user_id', $user->id)
            ->orderBy('places.id')
            ->distinct()
            ->get(['places.id', 'places.name', 'places.slug', 'places.address_line1', 'places.city', 'places.country_code'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lists(User $user): array
    {
        return DB::table('place_lists')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'name', 'is_public', 'public_slug', 'created_at'])
            ->map(function ($list) {
                $row = (array) $list;
                $row['places'] = DB::table('place_list_items')
                    ->join('places', 'place_list_items.place_id', '=', 'places.id')
                    ->where('place_list_items.place_list_id', $list->id)
                    ->pluck('places.name')
                    ->all();

                return $row;
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function placeTags(User $user): array
    {
        return DB::table('user_place_tags')
            ->join('places', 'user_place_tags.place_id', '=', 'places.id')
            ->where('user_place_tags.user_id', $user->id)
            ->get(['places.name as place', 'user_place_tags.label', 'user_place_tags.created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviews(User $user): array
    {
        return DB::table('reviews')
            ->join('places', 'reviews.place_id', '=', 'places.id')
            ->where('reviews.user_id', $user->id)
            ->get(['places.name as place', 'reviews.rating', 'reviews.body', 'reviews.created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Moderation flags this person filed (T-049).
     *
     * Their own words in `details` are theirs to have a copy of. What is NOT
     * here is the outcome: telling a reporter whether their flag succeeded is
     * how a malicious reporter learns how close they are to getting something
     * removed.
     *
     * @return list<array<string, mixed>>
     */
    private function reports(User $user): array
    {
        return DB::table('reports')
            ->where('reporter_user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'reportable_type', 'reportable_id', 'reason', 'details', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Handles only — never the email address or any other contact detail behind
     * them. A username is already visible to this user in the app; the rest of
     * that person's record is not theirs to take a copy of.
     *
     * @return array{following: list<string>, followers: list<string>}
     */
    private function follows(User $user): array
    {
        // The morph map (AppServiceProvider) stores the ALIAS `user`, never the
        // FQCN. Comparing against User::class here matched nothing and returned
        // an empty follow graph on every export — a silently incomplete Art. 20
        // response, which is the failure mode this whole file is written against.
        $morph = $user->getMorphClass();

        return [
            'following' => DB::table('follows')
                ->join('users', function ($join) use ($morph) {
                    $join->on('follows.followee_id', '=', 'users.id')
                        ->where('follows.followee_type', '=', $morph);
                })
                ->where('follows.follower_user_id', $user->id)
                // Someone who deleted their own account should not reappear in
                // another person's export as `deleted_01hxy…`.
                ->whereNull('users.deleted_at')
                ->pluck('users.username')
                ->all(),
            'followers' => DB::table('follows')
                ->join('users', 'follows.follower_user_id', '=', 'users.id')
                ->where('follows.followee_type', $morph)
                ->where('follows.followee_id', $user->id)
                ->whereNull('users.deleted_at')
                ->pluck('users.username')
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(User $user): array
    {
        return DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->orderBy('created_at')
            ->get(['id', 'type', 'data', 'read_at', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function devices(User $user): array
    {
        return DB::table('devices')
            ->where('user_id', $user->id)
            // Not the push token: it is a routing credential for the device, and
            // it is not a fact about the person.
            ->get(['id', 'platform', 'device_name', 'app_version', 'last_seen_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function redemptions(User $user): array
    {
        return DB::table('redemptions')
            ->leftJoin('offers', 'redemptions.offer_id', '=', 'offers.id')
            ->leftJoin('places', 'offers.place_id', '=', 'places.id')
            ->where('redemptions.user_id', $user->id)
            ->orderBy('redemptions.id')
            ->get([
                'redemptions.id', 'redemptions.status', 'redemptions.issued_at',
                'redemptions.redeemed_at', 'places.name as place', 'offers.title as offer',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledgerEntries(User $user): array
    {
        return DB::table('ledger_entries')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'account', 'direction', 'amount', 'currency', 'reference_type', 'reference_id', 'created_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payouts(User $user): array
    {
        return DB::table('payouts')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'status', 'amount', 'currency', 'created_at', 'paid_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function readme(User $user): string
    {
        $ttl = (int) config('gdpr.export_url_ttl_hours');

        return implode("\n", [
            'Reelmap — your data export',
            '',
            'Requested by: @'.$user->username,
            'Generated at: '.now()->toIso8601String(),
            '',
            'Every file is JSON, one per kind of record. Empty files mean we hold',
            'nothing of that kind for you.',
            '',
            'What is deliberately NOT here:',
            '  - Access tokens for your linked platform accounts. Those are live',
            '    credentials, not information about you.',
            '  - Other people\'s contact details. Where someone else appears (a',
            '    follower, a place you both shared) you get the handle you can',
            '    already see in the app, and nothing further.',
            '',
            "The download link for this archive expires {$ttl} hours after it was sent.",
            '',
        ]);
    }

    private function pathFor(User $user): string
    {
        // ULID, not a predictable name: the archive lives on a private disk, but
        // an unguessable key means a leaked or mis-scoped listing still does not
        // hand anyone a working path.
        return sprintf('exports/%d/reelmap-export-%s.zip', $user->id, strtolower((string) Str::ulid()));
    }

    private function disk(): string
    {
        return (string) config('media.disk');
    }
}
