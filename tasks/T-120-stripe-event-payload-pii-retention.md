# T-120 — Stripe identity PII is stored verbatim, forever, outside the deletion promise

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-045 (Stripe Connect Express: onboarding, payouts, webhooks),
  T-050 (GDPR: export/delete jobs + media retention)
- **Target paths:** `apps/api/app/Http/Controllers/StripeWebhookController.php`,
  `apps/api/app/Services/Gdpr/UserDataPurger.php`, `apps/api/routes/console.php`,
  `apps/api/database/migrations/`,
  `apps/api/resources/views/legal/privacy/en.blade.php`

Security review 2026-08-19, finding **SEC-3 (HIGH, CWE-359 / CWE-212)**.

## Context

`StripeWebhookController.php:78` persists `'payload' => $event->toArray()` — the
**whole event** — into `stripe_events.payload` (jsonb).

For Connect Express, `account.updated` carries `individual.dob`, legal name,
address, phone and bank last4. And:

- `stripe_events` appears **nowhere** in `UserDataPurger`;
- `routes/console.php` prunes exports, originals and source payloads, but **not
  this table**.

So the most sensitive identity data in the system sits in the one table with
neither a retention window nor a deletion path, and it survives `DELETE /me`
indefinitely.

It also makes a **published document false**. `privacy/en.blade.php:83` tells
users *"that data stays with Stripe"*. That sentence shipped in PR #194 (T-054)
and is currently untrue.

## Implementation

The handlers do not need the body. They read only `data.object.id` and payout
metadata, and `syncAccount()` **deliberately re-reads from the Stripe API**
rather than trusting the webhook. So:

- **Store the narrow projection** the handlers actually read.
- **Leave the webhook's structure alone.** The review called it exemplary —
  raw-body signature verification before parsing, 503 when unconfigured,
  idempotency insert before side effects. Keep the signature check and the
  idempotency insert exactly where they are; change only what goes into the
  `payload` column.
- **Add a `stripe_events` prune** next to the existing ones in
  `routes/console.php`, with the window in config.
- **Add the table to `UserDataPurger` by name**, and check that T-050's hourly
  reconciliation sweep sees it too.
- **Strip existing rows in the migration.** A fix that only applies to new rows
  leaves every row already written, which is all of them.

## Acceptance criteria

- [ ] A fixture `account.updated` carrying `individual.dob`, name, address,
      phone and bank last4 is processed and **none** of those values reaches
      `stripe_events` — asserted field by field on the stored row
- [ ] Idempotency, `data.object.id` and payout metadata are all still readable
      from what is stored; the handlers still pass
- [ ] A scheduled prune removes rows past the window (fixture row proves it)
- [ ] `UserDataPurger` covers `stripe_events` by name; `DELETE /me` leaves no
      row carrying that user's identity data
- [ ] Existing rows are stripped by the migration
- [ ] The privacy policy sentence is true of the code, asserted by the same test
      that reads the fixture

## Gotchas

- `UserDataPurger` collects storage objects **inside** the transaction and
  deletes them after commit. A new prune must not fight that ordering.
- Do not "fix" this by encrypting the column. Encryption at rest does not make
  the retention promise true, and the data is not needed at all.
- The privacy-policy assertion is the T-113 pattern: the document and the code
  must be pinned to each other by a test, not by everyone remembering.
