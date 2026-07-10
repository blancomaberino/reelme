# 05 — Mobile App Specification (Expo / React Native)

> Scope: the Reelmap mobile client. Backend contract is defined in the API spec (`/api/v1`, Laravel 13, Sanctum bearer tokens). This document is written for build agents and for a developer with **no prior React Native experience** — RN/Expo concepts are explained inline where non-obvious.

---

## 0. Stack (fixed — do not substitute)

| Concern | Choice | Notes |
|---|---|---|
| Framework | Expo, latest SDK, **managed workflow + config plugins** | Never `expo eject` / bare workflow. Native config is done via config plugins in `app.config.ts`. |
| Language | TypeScript (strict) | `"strict": true` in `tsconfig.json`. |
| Navigation | `expo-router` | File-based routing: files under `app/` **are** the screens/routes. |
| Share sheet receiver | `expo-share-intent` | Config plugin; requires a **dev client build** (does not work in Expo Go — see §6). |
| Maps | `react-native-maps` | `PROVIDER_DEFAULT` (Apple Maps) on iOS, `PROVIDER_GOOGLE` on Android. |
| Server state | TanStack Query (react-query) v5 | All API reads/writes go through queries/mutations. Never store server data in zustand. |
| Client state | zustand | Session/auth, UI flags only. Small, deliberate store. |
| Push | `expo-notifications` | Expo push tokens, backend fan-out via Expo Push API. |
| Secrets on device | `expo-secure-store` | Sanctum token lives here — **never** AsyncStorage. |
| HTTP | axios | Single configured instance with interceptors (§1.4). |
| Build/deploy | EAS Build + EAS Submit + EAS Update | Profiles in `eas.json` (§6). |
| API types | Generated from `packages/contracts` | No hand-written API types in the app (§1.6). |

**RN concept primer (read once):** A React Native app is React components rendered to native views (no DOM, no CSS files — styles are JS objects via `StyleSheet.create`). "Expo managed workflow" means you never open Xcode/Android Studio for config; native code and entitlements are generated at build time on EAS servers from `app.config.ts` + config plugins. "Expo Go" is a sandbox app for quick previews that only contains Expo's default native modules — any library with custom native code (like `expo-share-intent` or `react-native-maps` on Android) requires building your own **development client** (§6.1).

---

## 1. App architecture

### 1.1 Repository placement

The app lives in the monorepo at `apps/mobile`, consuming `packages/contracts` (shared with the Laravel backend's OpenAPI output — see §1.6).

```
apps/mobile/
  app/                     # expo-router routes (see 1.2)
  src/
    api/                   # axios client, generated types re-exports, endpoint hooks
      client.ts
      hooks/               # useShares(), usePlaces(), ... (TanStack Query wrappers)
    components/            # shared UI (Button, Sheet, PinMarker, VideoCard, ...)
    features/              # feature-local components + logic (map/, ingest/, wallet/, ...)
    stores/                # zustand stores (session.ts, ui.ts)
    lib/                   # utils (geo.ts, deeplink.ts, format.ts)
    notifications/         # push registration + handlers
  app.config.ts
  eas.json
  jest.config.js
  .maestro/                # E2E flows (§7)
```

### 1.2 expo-router navigation tree

**RN concept:** with expo-router, the `app/` directory maps to routes like Next.js. A `_layout.tsx` file defines the navigator (stack/tabs) that wraps sibling routes. Parentheses directories `(group)` group routes without affecting the URL/path. `[id]` is a dynamic segment. Every route is also a **deep link** automatically (scheme `reelmap://`), which we rely on for push-notification taps and the share extension.

```
app/
  _layout.tsx                    # Root layout: providers (QueryClient, theme), auth gate, share-intent listener
  (auth)/
    _layout.tsx                  # Stack, headerless
    welcome.tsx                  # Onboarding carousel + entry buttons        [M0]
    login.tsx                    # Email/password + social buttons           [M0]
    register.tsx                 # Registration                              [M0]
  (main)/
    _layout.tsx                  # Bottom tab navigator (see below)
    map/
      index.tsx                  # Map tab (default tab)                     [M2]
    feed/
      index.tsx                  # Feed tab                                  [M3]
    share/
      index.tsx                  # "+" tab: manual URL entry / share landing [M1]
    profile/
      index.tsx                  # Own profile + settings entry              [M0]
    wallet/
      index.tsx                  # Wallet tab — ONLY for influencer accounts [M4]
  share-ingest.tsx               # ShareIngest (modal) — share-sheet target  [M1]
  shares/[id]/status.tsx         # AnalysisStatus (live polling)             [M1]
  shares/[id]/review.tsx         # ExtractionReview form                     [M1]
  places/[id].tsx                # Place detail                              [M2]
  users/[handle].tsx             # Influencer / user public profile          [M3]
  search.tsx                     # Search (modal)                            [M2]
  offers/index.tsx               # Offers browse                             [M4]
  offers/[id]/redeem.tsx         # Redemption code/QR display                [M4]
  restaurant/offers.tsx          # Restaurant-owner offer management         [M4]
  restaurant/scan.tsx            # QR scan + verify                          [M4]
  notifications.tsx              # Notification center                       [M3]
  settings/
    index.tsx                    # Settings hub                              [M0]
    linked-accounts.tsx          # Link platform accounts (Instagram, ...)   [M1]
    ai-model.tsx                 # Model preference picker                   [M1]
    privacy.tsx                  # GDPR export / delete                      [M5]
  stripe-onboarding.tsx          # Stripe Connect onboarding webview         [M4]
```

**Tabs** (defined in `(main)/_layout.tsx` using `<Tabs>` from expo-router):

1. **Map** — default initial tab.
2. **Feed**.
3. **Share (+)** — center, visually prominent. Tapping opens `share/index.tsx` (manual URL paste path; the normal entry is the OS share sheet).
4. **Profile**.
5. **Wallet** — rendered conditionally: `href: session.user?.is_influencer ? '/wallet' : null` (expo-router hides a tab when `href` is `null`). Restaurant-owner tools live under Profile → "My restaurant", not a tab.

**Auth gating** in root `_layout.tsx`: on mount, read token from SecureStore → if present, fire `GET /api/v1/me` to hydrate the session store; render `(auth)` group via `<Redirect href="/(auth)/welcome" />` when unauthenticated, otherwise `(main)`. Use expo-router's protected-route pattern (check in root layout, not per-screen). Show a splash (`expo-splash-screen` kept visible) until the token check resolves, so users never flash the login screen.

### 1.3 State & data layer

**Rule: TanStack Query owns everything that came from the server. zustand owns everything that didn't.**

- **TanStack Query** — one `QueryClient` created in root layout. Conventions:
  - Query keys are structured arrays: `['places', 'map', bboxKey, filters]`, `['shares', id]`, `['me']`, `['wallet', 'ledger', page]`.
  - Central `queryKeys` factory in `src/api/keys.ts` — never inline string keys.
  - Defaults: `staleTime: 60_000` for content, `staleTime: 0` for wallet/ledger; `retry: 2` except mutations (`retry: 0`).
  - Mutations invalidate precisely (e.g., publishing a share invalidates `['places','map']` and `['me','shares']`).
  - Add `@tanstack/query-async-storage-persister` **only** in M2+ for map/place caches (offline-tolerant map browsing); auth-sensitive queries excluded.
- **zustand** stores:
  - `useSessionStore`: `{ user: Me | null, status: 'loading'|'authed'|'guest', setUser, clear }`. Token itself is NOT in the store (SecureStore only); the store holds the hydrated user object.
  - `useUiStore`: ephemeral flags (active map filters, pending share intent payload before navigation, bottom-sheet state).
  - **RN concept:** zustand works identically in RN as on web; no provider needed, hooks read directly.

### 1.4 Auth flow (Sanctum bearer token)

1. `POST /api/v1/auth/register` or `/auth/login` (or `/auth/social` with Apple/Google identity token) → response `{ token, user }`.
2. `await SecureStore.setItemAsync('api_token', token)` — SecureStore uses iOS Keychain / Android Keystore; survives app restarts, wiped on uninstall.
3. axios client (`src/api/client.ts`):
   - `baseURL` from config (§1.5) + `/api/v1`.
   - **Request interceptor:** reads token (cached in module memory after first SecureStore read; refreshed on login/logout) and sets `Authorization: Bearer <token>`, `Accept: application/json`.
   - **Response interceptor:** on `401` → clear SecureStore + session store → `router.replace('/(auth)/login')`. On `422` → normalize Laravel validation errors into `{ field: message }` for forms. On `429` → surface quota/rate-limit toast.
4. Logout: `POST /auth/logout` (revokes token server-side), then local clear.
5. Apple Sign-In is **mandatory** on iOS when any social login is offered (App Store rule) — use `expo-apple-authentication`; Google via `expo-auth-session`.

### 1.5 Environment & config — `app.config.ts`

Use the TS config (not static `app.json`) so env switching is programmatic:

```ts
// app.config.ts (shape, not exhaustive)
export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: IS_DEV ? 'Reelmap (Dev)' : 'Reelmap',
  slug: 'reelmap',
  scheme: 'reelmap',
  ios: { bundleIdentifier: IS_DEV ? 'pet.one.reelmap.dev' : 'pet.one.reelmap',
         config: { usesNonExemptEncryption: false } },
  android: { package: IS_DEV ? 'pet.one.reelmap.dev' : 'pet.one.reelmap',
             config: { googleMaps: { apiKey: process.env.GOOGLE_MAPS_ANDROID_KEY } } },
  extra: { apiUrl: process.env.EXPO_PUBLIC_API_URL, eas: { projectId: '<eas-project-id>' } },
  plugins: [
    'expo-router',
    'expo-secure-store',
    'expo-notifications',
    ['expo-share-intent', { iosActivationRules: { NSExtensionActivationSupportsWebURLWithMaxCount: 1,
                                                  NSExtensionActivationSupportsText: true },
                            androidIntentFilters: ['text/*'] }],
    ['react-native-maps', /* iOS: no key needed (Apple Maps); Android key above */]
  ],
});
```

- Env vars prefixed `EXPO_PUBLIC_` are inlined at build time and readable via `process.env.EXPO_PUBLIC_API_URL`. Per-profile values are set in `eas.json` `env` blocks (§6.2). Dev default: LAN IP of the Laravel dev server (a physical device cannot reach `localhost`).
- Separate bundle IDs for dev/prod let both apps install side-by-side.

### 1.6 API types from `packages/contracts`

- Backend publishes an OpenAPI 3.1 spec; `packages/contracts` runs `openapi-typescript` producing `types.gen.ts` plus a thin typed `paths` map.
- The app imports **only** from `@reelmap/contracts` — e.g. `Share`, `Place`, `ShareStatus`, request/response types per endpoint. `src/api/hooks/*` wrap axios calls with these types so TanStack Query hooks are fully typed end-to-end.
- CI step: regenerate + `tsc --noEmit` in `apps/mobile` fails the build on contract drift.

---

## 2. Share-sheet ingestion flow (the core UX)

This is Reelmap's signature interaction. Every state below must be implemented exactly.

### 2.1 Native plumbing — `expo-share-intent`

**RN concept:** on iOS, apps appear in the share sheet only via a **Share Extension** — a separate mini-binary embedded in the app. On Android, it's an `intent-filter` for `ACTION_SEND`. `expo-share-intent`'s config plugin generates both at EAS build time; there is nothing to write in Xcode/Android Studio, but **the feature only exists in a dev-client/production build, never in Expo Go**.

- **iOS activation rules** (what makes "Reelmap" show up): `NSExtensionActivationSupportsWebURLWithMaxCount: 1` (a shared URL — the Instagram/TikTok/YouTube/X case) and `NSExtensionActivationSupportsText: true` (some apps share the link as plain text). Do **not** enable image/video rules in M1 (screen-recording upload in §2.6 uses an in-app picker instead, keeping the share sheet targeted).
- **Android**: intent filter for `ACTION_SEND` with `mimeType text/*` (covers `text/plain` URLs shared by all four platforms).
- In-app API: `useShareIntent()` hook returns `{ hasShareIntent, shareIntent: { webUrl?, text? }, resetShareIntent }`. The root layout listens and routes:

```
if (hasShareIntent) {
  useUiStore.setState({ pendingShareUrl: extractUrl(shareIntent) });
  router.push('/share-ingest');
  resetShareIntent();
}
```

`extractUrl()` pulls the first URL out of `webUrl ?? text` (Instagram often shares `"Check this out! https://www.instagram.com/reel/..."` as text).

### 2.2 Happy path, step by step

1. **In Instagram** (or TikTok/X/YouTube): user watches a restaurant reel → taps **Share → Reelmap**.
2. OS launches/foregrounds Reelmap with the intent payload. Root layout detects it (§2.1) and pushes **ShareIngest** (`/share-ingest`, presented as a modal).
3. **ShareIngest screen**: URL prefilled in a read-only field with detected platform badge (parsed client-side from hostname: instagram.com / tiktok.com / x.com|twitter.com / youtube.com|youtu.be). One primary button: **"Analyze"**. If the user is not logged in, show inline login/register first — the pending URL is kept in `useUiStore` and the flow resumes after auth.
4. Tap Analyze → `POST /api/v1/shares { url }` → `201 { data: Share }` with `status: "pending"` → `router.replace('/shares/:id/status')`.
5. **AnalysisStatus screen** (`/shares/[id]/status`): shows a vertical stepper mirroring the backend pipeline **pending → fetching → analyzing → review → published/failed**, with the current stage animated. Implementation: `useQuery(['shares', id])` on `GET /api/v1/shares/:id` with `refetchInterval: (q) => isTerminal(q.state.data?.status) ? false : 2500` (poll every 2.5 s, stop at `review`, `published`, or `failed`). A push notification (§5) also arrives when the pipeline finishes; tapping it deep-links here — polling is the primary mechanism, push is the "user left the app" recovery.
6. When `status === "review"` → auto-navigate (`router.replace`) to **ExtractionReview**.
7. **ExtractionReview screen** (`/shares/[id]/review`): editable form of every extracted field from `share.extraction`:
   - Place name, address, cuisine, price range, tags, dish highlights, influencer handle + platform, original post URL (read-only), AI confidence per field (render low-confidence fields with a warning tint).
   - **Map pin adjust:** small embedded `MapView` centered on extracted lat/lng with a fixed center crosshair; user pans the map to move the pin (pan-map-under-fixed-pin is far more precise on mobile than dragging a marker). "Search address instead" fallback runs the backend geocode endpoint.
   - If the backend returned candidate place matches (dedupe), show "Is this the same place?" picker at the top — selecting one links to the existing place instead of creating a duplicate.
8. Tap **Confirm** → `POST /api/v1/shares/:id/confirm` with edited payload → `200 { data: Share (status: published), place }`.
9. **Published**: success screen (place card + confetti-lite) with buttons **"View on map"** (→ `/places/:id`, map tab focused on the pin) and **"Share another"**. Attribution (sharer = you, influencer, original post link) is shown on the place card exactly as it will appear publicly.

### 2.3 Cold start

The share intent frequently arrives while Reelmap is **not running**. `useShareIntent` handles both warm (app resumed) and cold (app launched by the intent) cases, but ordering matters:

- Root layout must process the intent **after** the auth check resolves. Sequence: keep splash → resolve token/`GET /me` → then read share intent → route. Never route to `/share-ingest` before providers are mounted (crash) and never drop the intent because the auth redirect fired first — hence staging the URL in `useUiStore.pendingShareUrl` before any redirect.
- If unauthenticated on cold start with an intent: `(auth)/login` renders a banner "Sign in to add this place" and, post-login, root layout sees `pendingShareUrl` and pushes `/share-ingest`.

### 2.4 Duplicate URL

`POST /shares` returns `409` with `{ error: { code: "duplicate_share", existing_share_id, place_id? } }` when this user already shared this URL (global duplicates are allowed — many users can share the same reel; only self-duplicates 409).

- UI: non-error treatment — "You already added this one." Buttons: **View place** (if `place_id`) / **View status** (if still processing). Never a red failure screen.

### 2.5 Failure states (AnalysisStatus, `status: "failed"`)

`share.failure_reason` (enum from contracts) drives the UI:

| `failure_reason` | UI copy + action |
|---|---|
| `unsupported_url` | "We can't read this link yet." → button back to ShareIngest |
| `fetch_failed` | "Couldn't load the post. It may have been deleted." → **Retry** (`POST /shares/:id/retry`) |
| `private_post` | Private-post flow — §2.6 |
| `not_a_restaurant` | "We couldn't find a restaurant in this video." → **Add manually** (opens ExtractionReview with empty fields) |
| `analysis_failed` | "Analysis failed." → **Retry** + option to switch AI model (link to `settings/ai-model`) |
| `quota_exceeded` | "You've used your analysis quota." → shows quota reset info from `GET /me` |

Every failed share remains listed under Profile → "My shares" so users can retry later.

### 2.6 Private post flow

When `failure_reason: "private_post"` (backend couldn't fetch the post content), AnalysisStatus renders a two-option sheet:

1. **"Link your Instagram account"** → `/settings/linked-accounts` → OAuth webview (`expo-web-browser` + backend-driven OAuth: `GET /platform-accounts/instagram/connect-url` → open → backend callback → app polls `GET /platform-accounts` until linked). Then **Retry** the share — the backend re-fetches using the linked account's access.
2. **"Add it manually"** → manual-fallback screen (part of the ingest feature):
   - Multiline paste field: "Paste the caption" .
   - **Upload a screen recording**: `expo-image-picker` (`mediaTypes: videos`) → upload via `POST /shares/:id/media` (multipart, show upload progress; cap 60 s / 100 MB client-side before upload).
   - Submit → `POST /shares/:id/manual` `{ caption?, media_id? }` → share re-enters `analyzing` → back to AnalysisStatus polling.

---

## 3. Screen inventory

Legend: **Phase** = roadmap milestone in which the screen ships. API paths are relative to `/api/v1`.

| # | Screen (route) | Purpose | Key components | API calls | Phase |
|---|---|---|---|---|---|
| 1 | Welcome `(auth)/welcome` | Onboarding: 3-slide value prop (share → map), entry to login/register | Carousel, CTA buttons | — | M0 |
| 2 | Register `(auth)/register` | Email/password sign-up | Form w/ 422 field errors | `POST /auth/register` | M0 |
| 3 | Login `(auth)/login` | Email/password + Apple/Google | Form, social buttons (`expo-apple-authentication`, `expo-auth-session`) | `POST /auth/login`, `POST /auth/social` | M0 |
| 4 | Link platform account `settings/linked-accounts` | Connect Instagram (etc.) for private-post fetch + influencer claim | List of platforms w/ status, OAuth webview launcher | `GET /platform-accounts`, `GET /platform-accounts/:platform/connect-url`, `DELETE /platform-accounts/:id` | M1 |
| 5 | ShareIngest `/share-ingest` | Landing for shared URL; manual paste also supported | URL field, platform badge, Analyze button, inline-auth gate | `POST /shares` | M1 |
| 6 | AnalysisStatus `/shares/[id]/status` | Live pipeline progress | Status stepper, polling query, failure sheets (§2.5–2.6) | `GET /shares/:id` (poll), `POST /shares/:id/retry`, `POST /shares/:id/manual`, `POST /shares/:id/media` | M1 |
| 7 | ExtractionReview `/shares/[id]/review` | Correct AI extraction before publish | Editable field list w/ confidence tints, pan-to-pin map, dedupe candidate picker | `GET /shares/:id`, `POST /shares/:id/confirm`, geocode endpoint | M1 |
| 8 | Map `(main)/map` | Core discovery surface | `MapView`, clustered markers, filter bar (cuisine, price, tags, following-only), place preview bottom sheet | `GET /map/places?bbox=&filters...` | M2 (following-only filter M3) |
| 9 | Place detail `/places/[id]` | Everything about one place | Header (name, cuisine, price, tags), source-video cards (thumbnail + platform badge + **link out to original post** via `Linking.openURL`; embedded YouTube player where available), influencer attribution row (sharer + influencer, tap → profile), offers section, mini-map | `GET /places/:id`, `GET /places/:id/shares`, `GET /places/:id/offers` (M4) | M2 (offers M4) |
| 10 | Feed `(main)/feed` | Recent shares from followed users/influencers; discovery for guests | Infinite list (`FlashList`) of share cards, pull-to-refresh | `GET /feed?cursor=` (infinite query) | M3 |
| 11 | Search `/search` | Find places, influencers, cuisines | Debounced input (300 ms), sectioned results | `GET /search?q=` | M2 (users M3) |
| 12 | Influencer/user profile `/users/[handle]` | Public profile: their map + shares | Header w/ follow button, embedded public map, shares grid | `GET /users/:handle`, `GET /users/:handle/places`, `POST/DELETE /users/:handle/follow` | M3 |
| 13 | Own profile `(main)/profile` | My shares (incl. failed/in-review), followers/following, settings entry | Tabs: Shares / Following; settings gear | `GET /me`, `GET /me/shares` | M0 shell, M1 shares, M3 social counts |
| 14 | Settings hub `/settings` | Account, notifications toggle, linked accounts, AI model, privacy, logout | List rows | `GET /me`, `POST /auth/logout` | M0 |
| 15 | AI model picker `/settings/ai-model` | Choose analysis model; view quotas | Radio list of models w/ descriptions + per-model quota bars | `GET /models`, `PATCH /me { model_preference }` | M1 |
| 16 | Privacy / GDPR `/settings/privacy` | Data export + account deletion | Export button (email delivery), delete w/ typed confirmation | `POST /me/export`, `DELETE /me` | M5 (App Store requires account deletion at launch — ship with first public release) |
| 17 | Offers browse `/offers` | Nearby/active offers | List + map toggle, offer cards | `GET /offers?lat&lng` | M4 |
| 18 | Redemption `/offers/[id]/redeem` | Claim → show code/QR to staff | Big QR (`react-native-qrcode-svg`) + alphanumeric code, expiry countdown, brightness bump while visible | `POST /offers/:id/redemptions` → `GET /redemptions/:id` (poll for `verified` → success state) | M4 |
| 19 | Offer management `/restaurant/offers` | Restaurant owner: CRUD offers | Offer list, create/edit form (title, discount, validity, redemption cap) | `GET/POST/PATCH/DELETE /restaurant/offers` | M4 |
| 20 | QR scan verify `/restaurant/scan` | Staff scans customer QR | `expo-camera` `CameraView` w/ `barcodeScannerSettings: { barcodeTypes: ['qr'] }`, result sheet (valid/expired/already-used) | `POST /restaurant/redemptions/verify { code }` | M4 |
| 21 | Wallet `(main)/wallet` | Influencer earnings | Balance card, ledger list (infinite), payout button, Stripe status banner | `GET /wallet`, `GET /wallet/ledger`, `POST /wallet/payouts` | M4 |
| 22 | Stripe onboarding `/stripe-onboarding` | Stripe Connect KYC | `WebView` (react-native-webview) loading `GET /wallet/stripe/onboarding-link` URL; intercept `return_url`/`refresh_url` navigation to close + refetch `GET /wallet` | M4 |
| 23 | Notifications `/notifications` | In-app notification center | Sectioned list, unread badges, tap → deep link per type (§5) | `GET /notifications`, `POST /notifications/read` | M3 |

---

## 4. Map UX specifics

### 4.1 Clustering

- Client-side clustering via **supercluster** (the library) fed by the current fetched place set — wrap `react-native-maps` markers manually rather than depending on unmaintained cluster wrappers. Cluster radius 50 px, `maxZoom 16`; beyond zoom 16 render raw pins.
- Cluster marker: circle with count; tap → `animateToRegion` fitting the cluster's expansion bounds.
- Pins carry cuisine glyph + color by price tier; use `tracksViewChanges={false}` on `<Marker>` after first render (critical Android perf lever — without it every marker re-rasterizes every frame).

### 4.2 Viewport-driven fetching

- On `onRegionChangeComplete` (fires when pan/zoom gesture ends — never fetch on `onRegionChange`, which fires per frame), debounce 400 ms, then `GET /map/places?bbox=west,south,east,north&zoom=z&filters...`.
- **Fetch inflation:** request a bbox padded ~40% beyond the viewport so small pans hit cache instead of the network.
- **Quantized query keys:** round bbox to a zoom-dependent grid before building the query key (`['places','map', quantizedBbox, zoomBand, filters]`) so tiny pans map to the same cached query. `staleTime: 120_000`, `placeholderData: keepPreviousData` so old pins stay visible while the new region loads (no blink).
- Server caps results (e.g. 300/request); above-cap responses include `truncated: true` → show "zoom in for more" chip.

### 4.3 Marker → bottom-sheet preview

- Tapping a pin opens a **bottom sheet** (`@gorhom/bottom-sheet`) at ~35% snap point: photo/video thumb, name, cuisine/price, influencer attribution line, distance, "View place" → `/places/:id`. Map stays interactive above the sheet; tapping another pin swaps sheet content in place (no dismiss/reopen).
- Selected pin gets an enlarged marker state; center the map on it offset upward so the sheet doesn't cover it.

### 4.4 Performance rules (re-render storms)

**RN concept:** `MapView` children re-render whenever their parent re-renders; with hundreds of markers this destroys frame rate. Rules:

1. `PlaceMarker` is `React.memo` with a comparator on `(id, selected)` only.
2. Marker `onPress` handlers are stable (`useCallback` with id passed via `identifier` prop), never inline closures per render.
3. Filter state and sheet state live in `useUiStore` selectors scoped so the `MapView` subtree doesn't subscribe to sheet-content changes.
4. Cluster recomputation runs in `useMemo` keyed by `[placesData, zoomBand]`.
5. Never store map region in React state (setState per gesture frame = death); read region from the event/ref when needed.
6. Long lists elsewhere in the app use **FlashList**, not `ScrollView`/`FlatList` defaults.

---

## 5. Push notifications

### 5.1 Registration

- On first authenticated launch (and after login): request permission (`expo-notifications` `requestPermissionsAsync` — on iOS this triggers the system prompt; gate it behind a soft pre-prompt explaining value), then `getExpoPushTokenAsync({ projectId })` → `POST /api/v1/devices { expo_push_token, platform, app_version }`.
- On logout: `DELETE /devices/:token`. Backend deactivates tokens on Expo push receipts with `DeviceNotRegistered`.
- Android requires a notification channel: create `default` channel with `importance: MAX` on startup (no-op on iOS).

### 5.2 Notification types → deep links

| Type (`data.type`) | Trigger | Tap destination |
|---|---|---|
| `share.published` | Analysis finished, auto-published | `/places/:place_id` |
| `share.review_needed` | Pipeline hit `review` | `/shares/:share_id/review` |
| `share.failed` | Pipeline failed | `/shares/:share_id/status` |
| `social.follow` | New follower | `/users/:handle` |
| `offer.redeemed` | (restaurant owner) redemption verified | `/restaurant/offers` |
| `redemption.verified` | (diner) staff verified your code | `/offers/:offer_id/redeem` success state |
| `wallet.payout` | Payout paid/failed | `/wallet` |

- Payload convention: `data: { type, url }` where `url` is an in-app path — the tap handler simply `router.push(data.url)`. One switch statement, no per-type routing logic.
- Foreground handler: `setNotificationHandler` shows banner except when the user is already on the target screen (compare `data.url` to current route); additionally, `share.*` notifications invalidate `['shares', id]` so an open AnalysisStatus updates instantly.
- Cold-start taps: check `getLastNotificationResponseAsync()` in root layout (same staging pattern as share intents, §2.3).

---

## 6. Expo / EAS workflow (RN-beginner oriented)

### 6.1 Dev client vs Expo Go

- **Expo Go** (App Store app) can only run projects using Expo's built-in native modules. Reelmap uses `expo-share-intent` (custom share extension) and `react-native-maps` → **Expo Go will not work. Do not use it.**
- Instead, build a **development client** once: `eas build --profile development --platform ios` (and android). This produces your own installable app containing all native modules + a dev launcher. After installing it on your device/simulator, daily development is just `npx expo start --dev-client` — JS changes hot-reload instantly over Wi-Fi.
- **When must you rebuild the dev client?** Only when native config changes: adding/removing a library with native code, editing plugins in `app.config.ts`, or upgrading the Expo SDK. Pure TS/JS/screen changes never require a rebuild.

### 6.2 `eas.json` build profiles

```jsonc
{
  "build": {
    "development": {
      "developmentClient": true, "distribution": "internal",
      "env": { "EXPO_PUBLIC_API_URL": "http://192.168.x.x:8000" },
      "ios": { "simulator": true }        // also make a device build for share-sheet testing
    },
    "preview": {                          // internal QA: TestFlight / APK
      "distribution": "internal",
      "env": { "EXPO_PUBLIC_API_URL": "https://staging.reelmap.app" },
      "channel": "preview"
    },
    "production": {
      "autoIncrement": true,
      "env": { "EXPO_PUBLIC_API_URL": "https://api.reelmap.app" },
      "channel": "production"
    }
  },
  "submit": { "production": { /* store credentials refs */ } }
}
```

Note: the **share sheet cannot be tested on the iOS simulator reliably** — always test ingestion on a physical device (development device build + real Instagram app).

### 6.3 Store submission

- **iOS:** Apple Developer account ($99/yr). `eas credentials` manages certificates/profiles automatically (accept defaults). `eas submit --platform ios` uploads to App Store Connect → TestFlight for testing → submit for review. Required for review: privacy "nutrition labels", account-deletion flow (§3 #16), Sign in with Apple, camera/photo/location purpose strings (set in `app.config.ts` `infoPlist`).
- **Android:** Google Play Console ($25 once). First submission must be uploaded manually (Play requires manual app creation); after that `eas submit --platform android` works. Expect Play's closed-testing requirements for new personal accounts (12 testers / 14 days) — start a closed track early in M1.

### 6.4 OTA updates — EAS Update policy

**RN concept:** EAS Update ships new **JS bundles** directly to installed apps (no store review). It can never change native code — that always requires a store build.

Policy:
- `channel` per profile (above); `runtimeVersion: { policy: "appVersion" }` so an update only reaches builds with compatible native code.
- Allowed OTA: bug fixes, copy, styling, screen logic. Not allowed OTA: anything touching plugins/native modules/permissions, and no feature launches that change store-reviewed behavior materially (store policy risk).
- Release flow: merge to `main` → CI runs `eas update --channel preview`; production updates are manual: `eas update --channel production --message "..."` after QA on preview.
- App checks for updates on launch (`checkAutomatically: ON_LOAD`, `fallbackToCacheTimeout: 0` — never block launch on download; update applies next launch).

---

## 7. Testing

### 7.1 Component tests — Jest + React Native Testing Library

- `jest-expo` preset; RNTL (`@testing-library/react-native`); network mocked with **msw** (msw/native) so hooks run against realistic `/api/v1` fixtures typed from `@reelmap/contracts` (fixtures imported from the contracts package's example payloads — drift breaks compilation).
- Render helpers wrap components in a fresh `QueryClient` + router mock per test.
- **Required coverage (critical flows, not blanket %):**
  1. ShareIngest: URL parsing from raw share text; unauthenticated gate stages URL and resumes post-login.
  2. AnalysisStatus: polling stops on terminal status; each `failure_reason` renders its mapped copy/action (§2.5 table-driven test).
  3. ExtractionReview: 422 validation errors map to fields; confirm mutation sends edited values; dedupe candidate selection switches payload shape.
  4. Auth: 401 interceptor clears session and redirects; token persisted to SecureStore mock.
  5. Map data hooks: bbox quantization produces stable query keys; filter changes invalidate correctly.
  6. Redemption: code display state machine (active → verified → expired).

### 7.2 E2E — Maestro

- Maestro flows in `.maestro/`, run against a **development build** with the app pointed at a mock API (env `EXPO_PUBLIC_API_URL` → local mock server replaying contract fixtures; deterministic, no real AI/Stripe).
- **Happy path flow (`share-to-publish.yaml`), the one non-negotiable E2E:** launch app → login (seeded test user) → open ShareIngest via deep link `reelmap://share-ingest?url=<fixture instagram url>` (Maestro cannot drive the real Instagram share sheet — deep link is the documented E2E entry point; the true share sheet is verified manually per release on a physical device) → tap Analyze → mock advances pending→fetching→analyzing→review → assert ExtractionReview shows fixture place name → edit name → Confirm → assert published success screen → "View on map" → assert pin/bottom sheet shows edited name.
- Secondary flows (added by phase): login/logout (M0), duplicate-URL 409 path (M1), map filter + place detail (M2), follow (M3), redemption display (M4).
- CI: component tests on every PR; Maestro on merge to `main` (Maestro Cloud or macOS runner + iOS simulator; share-sheet-dependent steps excluded as noted).

---

## 8. Phase mapping summary

| Phase | Mobile deliverables |
|---|---|
| **M0 Foundations** | Repo scaffold, expo-router shell, auth screens + Sanctum flow, SecureStore, axios client, contracts typegen, dev-client builds on EAS, profile/settings shells |
| **M1 Ingest & Analyze** | expo-share-intent wiring, ShareIngest/AnalysisStatus/ExtractionReview, failure + private-post + duplicate flows, linked accounts, model picker + quotas, push registration + `share.*` notifications |
| **M2 Map & Discovery** | Map tab (clustering, bbox fetching, filters), place detail, search, bottom-sheet preview |
| **M3 Social** | Feed, profiles + follow, following-only map filter, notification center, `social.*` push |
| **M4 Monetization** | Offers browse, redemption QR, restaurant offer management + scan verify, wallet + ledger + payouts, Stripe onboarding webview, `wallet.*`/redemption push |
| **M5 Hardening/Launch** | GDPR export/delete, perf passes (map, lists), Maestro suite complete, store assets, production submissions, EAS Update pipeline live |
