# Instagram OAuth linking — setup & go-live guide (T-015)

Linking a user's Instagram lets the ingestion pipeline fetch a **private** post
the sharer authorized, via the Instagram Graph API. This is the "Graph API with
linked user token" step of the adapter chain (`oEmbed → Graph → yt-dlp → manual`).

- **Flow:** `App\Http\Controllers\Api\V1\PlatformAccountController` (link / callback
  / list / unlink) → Socialite (`instagram` driver) → `App\Models\PlatformAccount`
  (encrypted tokens) → `App\Adapters\InstagramGraphAdapter` (reads the sharer's
  own media via `graph.instagram.com/me/media`).
- **Product:** *Instagram API with Instagram Login* (business login). **Not** the
  deprecated Basic Display API (shut down 2024-12-04), and **not** the
  "Facebook login" variant (that uses `graph.facebook.com` + a linked Page and
  does **not** match our adapter, which calls `graph.instagram.com`).
- **Without credentials configured, linking is simply unavailable** (`link`
  returns `503`); the keyless oEmbed path still runs, so shares of public posts
  are unaffected. This is the default/launch posture.

## Configuration

All wiring is `config/services.php → services.instagram`, fed by env (see
`.env.example`):

| Env var | Maps to | Notes |
|---------|---------|-------|
| `INSTAGRAM_CLIENT_ID` | Socialite client id | The **Instagram app** ID (from the Instagram-login product), **not** the top-level Meta/Facebook app ID. |
| `INSTAGRAM_CLIENT_SECRET` | Socialite client secret | The **Instagram app** secret. Treat as a secret — never commit; inject via the deploy secret store. |
| `INSTAGRAM_REDIRECT_URI` | OAuth `redirect_uri` | Public **HTTPS** URL of the callback route. Must match the value registered in the Meta app **character-for-character** (scheme, host, path, trailing slash). |
| `INSTAGRAM_SCOPES` | Requested scopes (CSV) | Default `instagram_business_basic` — enough for profile + `/me/media`. Granted scopes are stored per-account in `platform_accounts.scopes`. |
| `INSTAGRAM_GRAPH_BASE` | Graph host | Defaults to `https://graph.instagram.com`; override only for testing. |
| `INSTAGRAM_GRAPH_TIMEOUT` | `/me/media` request timeout (s) | Defaults to `10`. |

The callback route is:

```
GET /api/v1/platform-accounts/instagram/callback
```

So `INSTAGRAM_REDIRECT_URI` is `https://<host>/api/v1/platform-accounts/instagram/callback`.

## One-time Meta setup

### 1. Instagram account
The account being linked must be a **Professional** (Business or Creator) Instagram
account — personal accounts can't use this API. Convert it in the Instagram app:
Settings → *Account type and tools* → *Switch to professional account*. With
Instagram Login you do **not** need a Facebook Page.

### 2. Meta app
At <https://developers.facebook.com> → *My Apps → Create app*:

1. On the use-case step choose **"Other"** (a specific use case locks the app out
   of adding Instagram), then app type **Business**.
2. In the app dashboard: *Add product → Instagram → "API setup with Instagram
   login."*
3. That panel exposes the **Instagram app ID** and **Instagram app secret** →
   `INSTAGRAM_CLIENT_ID` / `INSTAGRAM_CLIENT_SECRET`.
4. Under *Business login settings*: add your **Valid OAuth Redirect URI(s)**
   (= `INSTAGRAM_REDIRECT_URI`). Set placeholder Deauthorize / Data-deletion URLs.
5. Keep scopes at `instagram_business_basic` unless a later feature needs more.

> Meta reshuffles this wizard often; if the labels differ, follow Meta's current
> **"Instagram API with Instagram Login — Get Started"** doc — the mapping to our
> env vars above stays the same.

## Testing before launch (Development mode)

While the app is in **Development mode** you can link and fetch your **own** media
without App Review — just add the Instagram account as a tester:

- App *Roles → Roles* → add the account as an **Instagram tester**.
- From within Instagram: Settings → *Apps and websites → Tester invites → Accept*.

Instagram won't redirect to `localhost`, so expose the API over HTTPS with a
tunnel and point `INSTAGRAM_REDIRECT_URI` (and the Meta console) at it:

```bash
cloudflared tunnel --url http://localhost:8080     # or: ngrok http 8080
# set INSTAGRAM_* in apps/api/.env, then:
docker compose exec -T laravel.test php artisan config:clear
```

Then: `POST /api/v1/platform-accounts/instagram/link` → open the returned
`authorize_url` in a browser logged into the tester account → authorize → the
callback redirects to `reelmap://platform-linked?...&status=ok` and a
`platform_accounts` row is created with a real token. On desktop the `reelmap://`
deep link just fails to open — that's expected; the link still succeeded (check
`GET /platform-accounts`). Share one of that account's own private reels to see
`InstagramGraphAdapter` resolve caption + video.

## Going live (public users)

1. **App Review / Advanced Access.** Development mode only works for the app's own
   testers. To let arbitrary users link their accounts, submit the
   `instagram_business_basic` permission for **Advanced Access** via App Review
   (business verification + a screencast of the link flow). Until approved, only
   testers can link.
2. **Production credentials & redirect.** Create/confirm the **production**
   Instagram app credentials and register the **production** callback
   (`https://api.<domain>/api/v1/platform-accounts/instagram/callback`) as a Valid
   OAuth Redirect URI. Set `INSTAGRAM_CLIENT_ID/SECRET/REDIRECT_URI` in the prod
   secret store (Forge env / secrets manager — see T-055), then `config:cache`.
3. **Enable linking.** Presence of client id + secret is the switch — once set,
   `link` stops returning `503`. No code deploy needed to toggle.
4. **Mobile deep link.** The callback ends at `reelmap://platform-linked?platform=…&status=…`.
   Ensure the app registers that scheme and handles `status` ∈
   `ok | conflict | invalid_state | error` (surface a retry/toast). This is the
   pending mobile follow-up (blocked on the mobile toolchain).
5. **Cost/quota.** `/me/media` counts against the app's Graph rate limit. The
   download path reuses the `media_url` resolved during fetch (no second call),
   and public posts by linked users skip Graph entirely — but monitor usage as
   linked-account volume grows.

## Token lifecycle & operational notes

- **Long-lived tokens (~60 days).** The stored token is Instagram's long-lived
  token; `platform_accounts.token_expires_at` records expiry. The adapter treats
  an expired token as **no token** (falls back to oEmbed/manual, parks the share
  as `fetch_auth_required`) rather than erroring. A background **`refresh()` job**
  to renew tokens before expiry is a planned follow-up (the seam exists; not yet
  wired) — until then, an expired link silently stops working and the user must
  re-link.
- **`APP_KEY` rotation bricks stored tokens.** Tokens use Laravel `encrypted`
  casts. Rotating `APP_KEY` without re-encrypting makes every stored token
  undecryptable → all links break and users must re-link. Never rotate `APP_KEY`
  casually in prod; if you must, plan a token re-encryption or a forced re-link.
- **Never query by token.** Encrypted columns can't be matched in SQL; look up by
  `user_id` / `platform` / `external_user_id` only.
- **One identity, one user.** `unique(platform, external_user_id)` +
  `unique(user_id, platform)` enforce it; the callback returns a `conflict` deep
  link if an Instagram identity is already linked to a different Reelmap user.
- **Revocation.** Unlinking (`DELETE /platform-accounts/{id}`) drops the local
  row (owner-only). Instagram Login has no documented server-side token-revoke
  endpoint; deleting the row stops all use. A user can also remove access from
  Instagram → *Apps and websites*.

## Go-live checklist

- [ ] Instagram account is Professional (Business/Creator).
- [ ] Meta app created as **Business** type with the **Instagram API with Instagram login** product.
- [ ] `instagram_business_basic` granted **Advanced Access** via App Review (for public users).
- [ ] Production `INSTAGRAM_CLIENT_ID/SECRET/REDIRECT_URI` set in the prod secret store; `config:cache` run.
- [ ] Production callback URL registered as a Valid OAuth Redirect URI (exact match).
- [ ] `GET /platform-accounts` and a real link round-trip verified against prod.
- [ ] Mobile app handles the `reelmap://platform-linked` deep link + `status` values.
- [ ] Token-refresh job scheduled (or a documented re-link policy accepted) before ~60-day expiry.
- [ ] `APP_KEY` rotation runbook notes the token-re-encryption / forced-relink implication.
