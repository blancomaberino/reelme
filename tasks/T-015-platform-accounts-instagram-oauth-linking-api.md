# T-015 — platform_accounts + Instagram OAuth linking (API)

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-011
- **Target paths:** `apps/api/database/migrations/`, `apps/api/app/Http/Controllers/PlatformAccountController.php`
- **Spec refs:** [02-data-model.md#platform_accounts](../02-data-model.md), [03-api-design.md#platform-accounts](../03-api-design.md)

## Context

Public-post adapters (T-013/T-014) can't fetch private posts; the spec's answer is user-linked platform accounts whose OAuth tokens let the pipeline fetch content the sharer is authorized to see (02 §3.2, 01 §5 chain step "Graph API with linked user token"). This task ships the `platform_accounts` table with encrypted token storage, the link/unlink REST endpoints (03 §2.3), and wires the Instagram Graph strategy into `InstagramAdapter`'s chain. It also underpins influencer claiming later (T-038). Application code lives in the separate app repo created by T-001 (under `apps/api/`), NOT this plans folder.

## Implementation steps

1. **Migration** `create_platform_accounts_table` exactly per 02 §3.2: `user_id` FK → users ON DELETE CASCADE; `platform varchar(16)` (+ CHECK against `Platform` enum values); `external_user_id varchar(255)`; `handle citext`; `access_token text` nullable; `refresh_token text` nullable; `token_expires_at timestamptz`; `scopes jsonb` default `'[]'`; `last_synced_at timestamptz`. Indexes: unique(`platform`,`external_user_id`), unique(`user_id`,`platform`), index(`handle`).
2. **Model** `App\Models\PlatformAccount` with casts:
   ```php
   protected function casts(): array
   {
       return [
           'platform' => Platform::class,
           'access_token' => 'encrypted',    // Laravel encrypted cast — never plaintext at rest
           'refresh_token' => 'encrypted',
           'scopes' => 'array',
           'token_expires_at' => 'immutable_datetime',
       ];
   }
   ```
   Relations: `belongsTo User`; `User hasMany platformAccounts`. Factory with a `->instagram()` state. Hide token attributes (`$hidden`) so they can never serialize into API responses.
3. **Socialite**: install `laravel/socialite` + `socialiteproviders/instagram` (resolve current package/versions at install time). Configure `services.instagram` (`client_id`, `client_secret`, `redirect` → the callback route). Register the provider event listener per SocialiteProviders docs.
4. **Routes + controller** `App\Http\Controllers\PlatformAccountController` (route group `/api/v1`, per 03 §2.3):
   - `GET /platform-accounts` (auth:sanctum) — list linked accounts: platform, handle, scopes, `token_expires_at`, status (`active`/`expired`); tokens never included.
   - `POST /platform-accounts/{platform}/link` (auth) — validate platform is `instagram` (422 others for now); return `{"data": {"authorize_url": ...}}` from `Socialite::driver('instagram')->stateless()->redirectUrl(...)->getTargetUrl()`, embedding a **signed state** parameter carrying the user id (HMAC or temporary signed URL token) since the callback arrives unauthenticated.
   - `GET /platform-accounts/{platform}/callback` (public, state-signed per 03) — verify state, exchange code via Socialite, then `updateOrCreate` on (`user_id`,`platform`) with `external_user_id`, `handle`, tokens, `scopes`, `token_expires_at`. Respond with a small HTML/redirect page suitable for the mobile in-app browser (deep link `reelmap://platform-linked?platform=instagram&status=ok`).
   - `DELETE /platform-accounts/{id}` (auth + policy: owner only) — delete the row (cascade-safe) after best-effort remote token revocation.
5. **Adapter integration**: implement the `InstagramGraphAdapter` strategy slot from 04 §2's chain (oEmbed → **Graph** → yt-dlp → manual). In `InstagramAdapter`, when a `LinkedAccount` DTO is provided (mapped from the sharer's `PlatformAccount` via a small `LinkedAccount::fromModel()` factory), and oEmbed reported the post private/unavailable, call the Graph API media endpoints with the user token to fetch caption + `media_url`. Missing/expired token → skip the strategy (`fetch_auth_required` taxonomy if the post is private and no token exists). `FetchSourcePost` (T-016) is responsible for loading the sharer's PlatformAccount and passing the DTO.
6. **Tests** (fake OAuth, no network):
   - Feature: link endpoint returns an `authorize_url` containing redirect + state; callback with mocked Socialite (`Socialite::shouldReceive('driver->stateless->user')` returning a fake user object with token/expiresIn/id/nickname) creates the row; re-link updates instead of duplicating (unique `user_id`,`platform`); unlink deletes and 403s for non-owners; list response contains no token fields (assert `access_token` absent from JSON).
   - Unit: encrypted cast round-trip — raw DB value is not the plaintext token (`DB::table('platform_accounts')->value('access_token') !== 'tok'`) but the model accessor returns it.
   - Adapter: with a `LinkedAccount` + `Http::fake` Graph fixtures, private post resolves caption/media; without token → `fetch_auth_required` path.

## Acceptance criteria

- [ ] `platform_accounts` table matches 02 §3.2 (columns, defaults, unique(`platform`,`external_user_id`), unique(`user_id`,`platform`), index(`handle`), CASCADE delete with user).
- [ ] `access_token`/`refresh_token` use Laravel `encrypted` casts; ciphertext at rest verified by test; tokens hidden from all serialization.
- [ ] `GET /platform-accounts`, `POST /platform-accounts/{platform}/link`, `GET /platform-accounts/{platform}/callback`, `DELETE /platform-accounts/{id}` implemented per 03 §2.3 with Sanctum auth (callback public but state-signed) and owner-only unlink.
- [ ] OAuth flow uses Socialite (Instagram provider); callback stores external_user_id, handle, tokens, scopes, expiry via updateOrCreate.
- [ ] `InstagramAdapter` uses the linked token to fetch a private post the sharer authorized, slotted between oEmbed and yt-dlp in the chain; absent token on a private post maps to `fetch_auth_required`.
- [ ] All tests use fake OAuth (Socialite mock) and `Http::fake` — no live calls; suite green.
- [ ] `pint --test` and `phpstan analyse` pass.

## Verification

```bash
cd apps/api
php artisan migrate && php artisan test --filter=PlatformAccount
php artisan tinker --execute="
  \$pa = \App\Models\PlatformAccount::factory()->create(['access_token' => 'secret-token']);
  echo \DB::table('platform_accounts')->where('id', \$pa->id)->value('access_token') === 'secret-token' ? 'PLAINTEXT-BUG' : 'encrypted-ok';
  echo ' / ', \$pa->fresh()->access_token;
"
# expect: encrypted-ok / secret-token
curl -s -H "Authorization: Bearer $TOKEN" -X POST localhost/api/v1/platform-accounts/instagram/link | jq .data.authorize_url
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

## Gotchas

- **Encrypted casts + APP_KEY rotation**: rotating `APP_KEY` bricks stored tokens; note this in the runbook seam and never seed real tokens. Encrypted values also break `where('access_token', ...)` queries — never query by token.
- The callback is unauthenticated: the **state must be signed and single-use** (cache a nonce, 10 min TTL) or an attacker can link their Instagram account to a victim's Reelmap account (login CSRF).
- Instagram's OAuth product landscape shifts (Basic Display deprecated → Instagram API with Instagram Login / Facebook Login). Resolve the current SocialiteProviders driver and scopes at implementation time; keep scope list in config, store granted scopes in `scopes`.
- Token expiry: Instagram long-lived tokens last ~60 days and are refreshable; store `token_expires_at`, and have the adapter treat expired tokens as "no token" rather than failing the share. Actual refresh job can be a follow-up; leave a `refresh()` seam.
- Unique(`user_id`,`platform`) means one Instagram account per user — use `updateOrCreate`, and surface a 409 `conflict` if the same Instagram `external_user_id` is already linked to a *different* user.
- Do not change the `SourceAdapter` interface from T-012 — `LinkedAccount` DTO was designed exactly so this task plugs in without contract churn.
- `handle` is `citext` — same raw-statement column trick as T-011.
