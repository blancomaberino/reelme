# Data rights: export & deletion (T-050)

How a person gets a copy of their data, how they get rid of it, and what we keep
anyway. NFR-10, R-06, and Apple Guideline 5.1.1(v).

## The two endpoints

| | |
|---|---|
| `POST /api/v1/me/export` | 202. Queues `ExportUserData`; the archive is emailed as a signed, expiring link. |
| `DELETE /api/v1/me` | 200 with `purge_at`. Soft-deletes, revokes **every** token, queues `PurgeUserData` for after the grace period. |

Both act on the caller and take no user id, which is what makes them safe to
expose to any authenticated session without a policy. Both are throttled 5/min.

## Deletion is two events, not one

1. **Immediately** — `deleted_at` and `deletion_requested_at` are set, all
   Sanctum tokens are deleted. Every device is signed out on its next request.
2. **After `GDPR_PURGE_GRACE_DAYS` (default 14)** — `PurgeUserData` erases.

Signing back in inside the window cancels it and restores the account exactly as
it was. That is the whole reason the first step is a *soft* delete: the row has
to survive long enough to be restorable.

> **`deleted_at` alone does not mean "the user asked".** An admin **ban** is
> also a soft delete. `deletion_requested_at` is the column that tells them
> apart, and every gate keys on `AccountDeletion::isPending()`, never on
> `trashed()`. Get this wrong and "sign back in to cancel" becomes a
> self-service unban — it did, once, during T-050; the ban suite caught it.

## What the purge does, per surface

**Hard-deleted** — purely personal, nothing else hangs off it:
`platform_accounts` (the OAuth tokens — live credentials, and the most dangerous
thing here to leave behind), `devices`, `personal_access_tokens`, `sessions`,
`notifications`, `follows` (both directions), `reviews`, `review_reports`,
`place_lists`, `user_place_tags`, `hidden_places`, `feed_dismissals`,
`invitations`, `influencer_claims`, `place_claims`, verification codes, password
resets, the avatar object, and **unpublished shares** with their analysis runs,
corrections and media.

**Anonymised, not deleted** — somebody else's record too: `place_edits.user_id`,
`place_merges.performed_by_user_id`, the `reviewed_by_user_id` columns, and
`redemptions.redeemed_by_user_id` (a code this person scanned as venue staff is
the restaurant's billing record).

**Kept** — `ledger_entries`, `redemptions`, `payouts`, published `shares` and
the `places`/`place_sources` they created. Note that "kept" is not "untouched":
a `redemptions` row this person scanned as venue staff keeps its billing facts
but loses `redeemed_by_user_id` (see above). What survives in full is the
financial record; what goes is the personal reference inside it.

**The user row survives, scrubbed.** `name` → `Deleted user`, `username`/`email`
→ ULID-suffixed values on `@reelmap.invalid` (uniquely indexed, so a shared
literal would make the *second* deletion fail), everything else nulled, Scout
index dropped.

### Why the row cannot simply be deleted

`ledger_entries` is append-only and legally retained (ADR-009), and it anchors
on `users.id`. Dropping the anchor does not anonymise the books — it corrupts
them, and the nightly balance check starts failing for a reason nobody can
reconstruct. `shares.user_id` is `NOT NULL` and `CASCADE`, so a hard delete
would also take published community places' attribution with it.

### Money still in flight

If a `pending`/`processing` payout exists, `stripe_connect_account_id` is the
one field held back — the transfer has to land somewhere. Everything else is
erased on schedule, and the hourly `reelmap:gdpr:sweep-deletions` revisits the
account until the payout settles. (`PurgeUserData` does NOT re-dispatch itself:
under a `sync` queue connection that is an infinite loop.)
The deletion copy in the app discloses this: retention law overrides erasure for
transaction records, and for an influencer or restaurant owner that is *the*
material fact about deleting their account.

## The export archive

One JSON file per entity plus a `README.txt`, zipped to the private media disk,
delivered by a signed URL with a `GDPR_EXPORT_URL_TTL_HOURS` (24) lifetime. The
link goes in the **email only** — the in-app notification row keeps its content
for months, and the archive is the densest personal file we ever produce.

Two things are deliberately absent, and both are tested:

- **Platform access/refresh tokens and Expo push tokens.** Live credentials, not
  facts about the person.
- **Other people's contact details.** Where someone else appears you get the
  handle already visible in the app, and nothing further.

## Operating it

```bash
# Who is pending erasure, and when
php artisan tinker --execute="App\Models\User::onlyTrashed()
  ->whereNotNull('deletion_requested_at')
  ->get(['id','deletion_requested_at'])->each(fn(\$u) => print(\$u->id.' '.\$u->deletion_requested_at.PHP_EOL));"

# Run an ALREADY-DUE purge synchronously (idempotent — safe after a partial
# failure). This does NOT bypass anything: the job re-checks that the deletion
# is pending, is the user's own, and is past its grace period, and returns
# without erasing if any of that fails. Quote the id or the shell eats it.
php artisan tinker --execute="App\Jobs\Gdpr\PurgeUserData::dispatchSync(123);"

# Retention sweeps
php artisan reelmap:media:prune-originals --dry-run
php artisan reelmap:sources:prune-payloads --dry-run
php artisan reelmap:gdpr:prune-exports
```

Jobs run on the **`housekeeping`** queue with its own Horizon supervisor — a
purge walks a dozen tables and must never sit in front of a push somebody is
waiting on. A supervisor has to be listed per environment in `config/horizon.php`,
not only in `defaults`, or the queue has no worker and jobs enqueue into silence.

## Log lines to grep

`gdpr.deletion.requested` · `gdpr.deletion.cancelled` · `gdpr.purge.completed` ·
`gdpr.purge.skipped` · `gdpr.purge.deferred_financial_linkage` ·
`gdpr.export.completed` · `media.prune.deleted` · `gdpr.sweep.dispatched`

None carry direct identifiers — no email, name, handle, token or archive path.
They do carry `user_id`, which is **pseudonymous**: meaningless on its own, but
re-identifying for anyone who can also read the database. Treat application logs
as in-scope for the retention and access controls that covers, and do not add a
field to these that would make a log line identifying by itself.
