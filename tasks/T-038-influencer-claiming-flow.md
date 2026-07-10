# T-038 — Influencer claiming flow

- **Phase:** M3 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-036, T-015
- **Target paths:** `apps/api/app/Http/Controllers/InfluencerClaimController.php`
- **Spec refs:** [../06-monetization.md#influencer-program](../06-monetization.md#influencer-program), [../02-data-model.md#influencers](../02-data-model.md#influencers)

## Context
`influencers` rows are auto-created from `source_posts` authors and exist independently of Reelmap accounts (data-model §3.3). This task lets the real person claim that identity — the M3 exit criterion ("an influencer can claim their auto-created identity") and the prerequisite for M4 payouts and escrowed-earnings release. T-015 shipped `platform_accounts` OAuth linking (used by the OAuth verification method); T-036 shipped the influencer profile the claim button hangs off. Two verification methods per 06 §5.1: **code-in-bio (primary)** and **platform OAuth match (secondary/best-effort)**. App code lives in the separate app repo created by T-001 (`apps/api`).

## Implementation steps
1. **Route**: `POST /api/v1/influencers/{influencer}/claim` (03 §2.9), auth `user`, controller `App\Http\Controllers\Api\V1\InfluencerClaimController` (physical file per target path). Request body: `{method: "oauth"|"bio_code"}` plus method-specific fields below. Add `GET /api/v1/influencers/{influencer}/claim` to return the caller's in-progress claim state (token, status) so the mobile flow (T-039) can resume.
2. **Method A — platform OAuth match** (automatic, single request):
   - Look up the caller's `platform_accounts` row where `platform_accounts.platform = influencers.platform` and the handle matches: compare `platform_accounts.handle` (citext) against `influencers.handle` (normalized, no leading `@`); prefer `external_user_id` when the influencer row has one recorded.
   - Match → claim verified immediately (step 4). No match → 422 `validation_failed` with a message pointing at bio-code as the fallback. Per 06 §5.1 this is best-effort (Instagram Basic Display is dead) — never block on it.
3. **Method B — code-in-bio** (two requests):
   - `POST .../claim {method: "bio_code"}` with no token yet → generate a one-time token (e.g. `reelmap-verify-<8 char base32>`), persist it against (influencer_id, user_id) with a 72h expiry — either a small `influencer_claims` table (`influencer_id`, `user_id`, `token`, `status pending|verified|rejected`, `expires_at`, `reviewed_by_user_id`) or a signed cache entry; a table is preferred because rejection + admin override (step 5) need durable state. Return the token + instructions ("place this in your {platform} bio or a pinned post").
   - `POST .../claim {method: "bio_code", action: "verify"}` → backend fetches the influencer's public profile via the existing platform adapter layer (the M1 `SourceAdapter`/oEmbed plumbing; add a `fetchProfileBio(platform, handle)` capability) and checks the token appears in the bio (or pinned-post caption). Found → verified (step 4). Not found → 422 with `details.reason: token_not_found`, claim stays `pending` for retry until expiry.
4. **On verification (both methods)**, in one DB transaction:
   - Guard: `influencers.claimed_by_user_id IS NULL` — use a conditional update (`UPDATE influencers SET claimed_by_user_id = ?, claimed_at = now() WHERE id = ? AND claimed_by_user_id IS NULL`) and treat 0 affected rows as a lost race → 409 `conflict`.
   - Set `users.is_influencer = true` (data-model §3.1: "set when an influencers row is claimed").
   - Mark the claim row `verified`; expire competing `pending` claims for the same influencer to `rejected` with reason `claimed_by_other`.
   - (M4 hook, do not build now: claiming later releases `influencer_escrow` per 06 §5.3 — leave a domain event `InfluencerClaimed` for the ledger to subscribe to.)
5. **Rejection + admin override in Filament**: `InfluencerClaimResource` (`apps/api/app/Filament/Resources/`) listing claims with status filter; admin actions: **approve** (runs step 4 path, may override an existing `claimed_by_user_id` for dispute resolution per 06 §5.1 — unset the previous claimant's link and, if they hold no other claimed influencer, clear their `is_influencer` flag), **reject** (status `rejected`, notify claimant). Disputes = two verified-intent claims → surfaced as a badge/filter in the resource.
6. **Authorization/validation**: an influencer already claimed by the caller → 200 no-op; claimed by someone else → 409 `conflict` (dispute hint: "contact support"). One `pending` bio-code claim per (user, influencer) — regenerating the token replaces it.
7. **Contracts + tests**: JSON Schema for claim request/response. Pest tests:
   - OAuth match success (seeded `platform_accounts` handle == influencer handle) → `claimed_by_user_id` set, `is_influencer` true;
   - OAuth handle mismatch → 422, influencer untouched;
   - bio-code issue → token returned; verify with mocked profile fetch containing token → verified; without token → 422 pending;
   - expired token → 422 `details.reason: token_expired`;
   - claim race / already-claimed → 409;
   - Filament reject + admin override covered by a feature test hitting the resource actions (Livewire test).

## Acceptance criteria
- [ ] `POST /api/v1/influencers/{id}/claim` with `method: "oauth"` verifies instantly when a linked `platform_accounts` row matches the influencer's `platform` + `handle` (or `external_user_id`), and fails 422 otherwise.
- [ ] Bio-code flow: first call issues a one-time token with 72h expiry; verify call fetches the platform bio and only verifies when the token is present; not-found and expired cases return 422 with machine-readable `details.reason`.
- [ ] On success (either method): `influencers.claimed_by_user_id` + `claimed_at` set atomically, `users.is_influencer = true`, competing pending claims auto-rejected, and an `InfluencerClaimed` event is fired.
- [ ] Claiming an influencer already claimed by another user returns 409 `conflict` and never overwrites; re-claiming one you own is an idempotent 200.
- [ ] Filament `InfluencerClaimResource` supports reject (with claimant notification) and admin override reassignment, including clearing the previous claimant's link.
- [ ] Pest tests cover **both** verification methods (success + failure each), token expiry, the claim race (conditional-update path), and admin override.
- [ ] Claim endpoints are rate-limited (reuse default 60/min; bio verify additionally capped, e.g. 5/min, to bound profile-fetch cost).

## Verification
```bash
cd apps/api
php artisan migrate && composer test -- --filter=InfluencerClaim
vendor/bin/pint --test && vendor/bin/phpstan
```
Manual:
```bash
TOKEN=... # user with a linked Instagram platform_account handle == influencer handle
curl -s -X POST http://localhost:8000/api/v1/influencers/01J.../claim \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"method":"oauth"}' | jq   # → data.status = "verified"
curl -s http://localhost:8000/api/v1/influencers/01J... | jq .data.claimed  # → true
# bio-code (second user, second influencer):
curl -s -X POST .../claim -d '{"method":"bio_code"}' | jq .data.token
# put token in a test fixture bio, then:
curl -s -X POST .../claim -d '{"method":"bio_code","action":"verify"}' | jq .data.status
```
Filament: log in as admin at `/admin`, open Influencer Claims, reject a pending claim → claimant gets a notification; override a claimed influencer → link moves to the new user.

## Gotchas
- **Claim race:** two users verifying simultaneously must not both win — the `WHERE claimed_by_user_id IS NULL` conditional update (row-level, atomic) is the guard; a read-then-write check is not sufficient.
- **Claiming an influencer someone else claimed:** always 409, never silent reassign — reassignment is exclusively the Filament admin-override path (06 §5.1 disputes are manual).
- **Handle drift:** influencers rename on-platform; match on `external_user_id` when available and treat handle-only matches as weaker evidence. Handles are `citext` — don't add your own `lower()` and break index usage.
- **Bio fetch fragility:** platform bios are fetched via scraping/oEmbed — rate-limit verify attempts, cache fetch failures briefly, and surface "try again later" (503-ish 422 reason) instead of burning the token on transient fetch errors.
- **Don't touch money here:** escrow release is M4 ledger work; emit `InfluencerClaimed` and stop — but DO emit it now, or M4 retroactive grants (06 §5.3) will need a backfill.
- **`is_influencer` unset on override:** when admin reassigns a claim, only clear the loser's `is_influencer` if they have no other claimed influencer rows — the flag gates the Wallet tab in mobile (05 §1.2).
- **Route phase mismatch:** 03 §2.9 tags the claim endpoint M4, but ROADMAP M3 scope and this task own it — ship it now; M4 only consumes it.
