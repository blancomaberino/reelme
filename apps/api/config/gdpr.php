<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deletion grace period (T-050, NFR-10)
    |--------------------------------------------------------------------------
    | `DELETE /me` takes effect immediately from the user's side — the account
    | is soft-deleted and every token revoked in the same request. The
    | irreversible half waits.
    |
    | The delay exists because erasure is genuinely irreversible and the request
    | is one tap away from a rage-quit. Signing back in inside the window undoes
    | it; after it, nothing can. Set to 0 to purge on the next queue pass (the
    | job still runs through the same code path).
    */
    'purge_grace_days' => (int) env('GDPR_PURGE_GRACE_DAYS', 14),

    /*
    | The queue purge and export run on. Housekeeping is deliberately NOT the
    | `notifications` queue: a purge walks a dozen tables and must never sit in
    | front of a push somebody is waiting on.
    |
    | Deliberately NOT an env knob. It has to name a queue some supervisor in
    | config/horizon.php actually listens to, and an environment that pointed it
    | elsewhere would accept every purge and run none of them — an erasure that
    | never happens, reported as success. A constant cannot drift.
    */
    'queue' => 'housekeeping',

    /*
    |--------------------------------------------------------------------------
    | Export archives
    |--------------------------------------------------------------------------
    | The download link is signed and short-lived: the archive is the single
    | densest collection of a user's personal data we ever produce, and a link
    | that does not expire is a permanent copy of it sitting behind a URL.
    */
    'export_url_ttl_hours' => (int) env('GDPR_EXPORT_URL_TTL_HOURS', 24),

    /*
    | How long the archive file itself is kept on the private disk before the
    | prune command removes it. Longer than the link TTL so a user who lets the
    | first link lapse can be re-sent one without regenerating.
    */
    'export_retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 7),
];
