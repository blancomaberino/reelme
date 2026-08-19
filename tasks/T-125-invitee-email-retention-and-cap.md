# T-125 — Invitee email addresses are kept forever, undisclosed, and uncapped

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-069 (invite friends by email), T-050 (GDPR export/delete + retention)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/InviteController.php`,
  `apps/api/routes/console.php`, `apps/api/config/quotas.php`,
  `apps/api/resources/views/legal/privacy/`

Security review 2026-08-19, finding **SEC-13 (LOW, CWE-359)**.

## Context

`InviteController.php:53` — `Invitation::create([... 'email' => $email ...])` —
stores a **third party's** address.

`UserDataPurger.php:313-314` deletes invitations in both directions when a *user*
is purged. But a person who was invited and never signed up has no account, no
notice, and no purge path, and **no retention job touches `invitations`**. The
privacy policy's "What we collect" and "How long we keep it" never mention it.

That is the finding: not a leak — an undisclosed, indefinite retention of someone
else's identifier by someone who never agreed to anything.

Second-order, noted in passing by the reviewer: 20 addresses × 10 requests/hour =
**200 recipients/hour per account**, with no daily cap.

## Implementation

- **Prune `invitations` on a fixed window**, scheduled next to the existing
  prunes in `routes/console.php`, window in config.
- **Disclose it.** Both "What we collect" and "How long we keep it".
- **Pin the prose to the config**, the T-113 way: that task asserts the age the
  code enforces and the age the published terms *state* come from one value.
  Do the same for the retention window, or the document and the job will drift
  the first time either changes.
- **A per-user daily invite cap**, with **its own error code**.

## Acceptance criteria

- [ ] `invitations` are pruned on a fixed window — proven with a fixture row past
      the window and one inside it
- [ ] The privacy policy names invitee addresses under both headings, and the
      window in the prose comes from the same config value the prune reads
      (asserted equal)
- [ ] A per-user daily cap is enforced and answers its own error code, distinct
      from `rate_limited`
- [ ] The existing two-directional purge (`UserDataPurger.php:313-314`) still
      passes

## Gotchas

- **T-051 already paid for the error-code trap, twice.** `abort(429)` renders as
  `rate_limited` — the same code the burst limiter emits — so someone who tapped
  twice quickly and someone out for the day get told the same thing. And
  `quota_exhausted` is **already taken twice** (`App\Services\AI\Exceptions\
  QuotaExhausted`, and a share `review_reason`). Pick a new, specific name.
- The daily cap is the smaller half. Do not let it become the headline of the PR
  — the retention and the disclosure are the finding.
