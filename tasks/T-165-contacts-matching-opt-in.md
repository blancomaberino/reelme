# T-165 — Opt-in contacts matching, with nothing raw leaving the device

- **Phase:** GROW · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-164
- **Target paths:**
  `apps/mobile/`,
  `apps/api/app/Http/Controllers/Api/V1/`,
  `apps/api/database/migrations/`

## Why

The highest-hit-rate way to find people in a WhatsApp-saturated market, and the
one with a real privacy surface: Apple 5.1.1(i) requires explicit, informed
consent before a contacts read, and GDPR governs everything after it -- including
the contacts of people who never signed up.

Owner decision D3 accepts the cost. This task is filed with the controls as
acceptance criteria rather than as guidance, because the controls are the
feature.

## Acceptance

- No contacts read happens before an in-app consent screen that states what is sent and what is stored; declining leaves every other part of the app fully usable
- Only salted hashes leave the device; a test asserts no raw phone number or email appears in any request payload
- The server stores no raw contact identifiers, and a user can delete their matches and their uploaded hashes, verified by a test
- Non-users' hashes are not retained beyond the match window
- The consent is separate from analytics and marketing consent (NFR-10)
- Re-running a match is idempotent and rate-limited per user

## Notes

Filed 2026-09-03 from owner decision D3. Deliberately sequenced after T-164 so the zero-privacy-cost routes ship first and this can be judged on marginal value.
