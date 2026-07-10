# T-034 — Feed API + mobile Feed and Search screens

- **Phase:** M2 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-030, T-031
- **Target paths:** `apps/api/app/Http/Controllers/FeedController.php`, `apps/mobile/app/(tabs)/feed.tsx`, `apps/mobile/app/search.tsx`
- **Spec refs:** [../03-api-design.md#feed](../03-api-design.md) (§2.8, §2.11), [../05-mobile-app.md#screen-inventory](../05-mobile-app.md) (§3 #10, #11)

## Context

With places (T-030) and search (T-031) live, this task adds the two remaining M2 discovery surfaces: a reverse-chronological feed of published shares (global scope in M2; `following` personalization arrives with T-037 in M3) and the Search screen over `GET /search`. Both navigate into T-033's Place detail. App code lives in the separate app repo created by T-001; controllers under `App\Http\Controllers\Api\V1`, mobile routes per the T-004 router scaffold (tasks.json paths; 05-mobile-app groups the feed tab under `(main)/feed/index.tsx` — follow the scaffold's actual group directory).

## Implementation steps

1. **Feed endpoint.** `GET /api/v1/feed` → `FeedController@index` in the v1 group. Params: `scope` in `global|following` (default `global`), cursor pagination (`cursor`, `limit` 1–100 default 25). M2 behavior: `global` = shares with `status = published`, ordered `published_at` desc (+ `id` tiebreaker), whose place is `active`; `following` requires auth and returns an empty page with `meta.scope: "following"` until T-037 (validated stub — the endpoint contract is stable now so the mobile screen needs no change in M3). Auth per spec: global is public.
2. **Feed item resource** (`FeedItemResource`): share id, `published_at`, sharer (`UserSummaryResource`, respecting `is_public`), source post (platform, original URL, caption excerpt, thumbnail signed URL), influencer summary, and the place summary (`PlaceSummaryResource` incl. slug, name, cuisine, price, lat/lng) via `share.publishedPlaceSource.place`. Envelope `{data: [], meta: {pagination}}`. Eager-load the full chain (`publishedPlaceSource.place.tags`, `sourcePost.influencer`, `sourcePost.mediaAssets` thumbnail, `user`) — no N+1.
3. **API tests.** Pest: global feed lists only published shares of active places in reverse-chron; cursor walk stable (no dupes/gaps across pages when new shares are published mid-walk — cursor, not offset, guarantees this: assert it); `scope=following` unauthenticated → 401, authenticated → empty + meta; hidden/merged places' shares excluded; validation + rate-limit headers.
4. **Mobile Feed screen** (`app/(tabs)/feed.tsx`):
   - `useInfiniteQuery(queryKeys.feed(scope), fetchPage, { getNextPageParam: (last) => last.meta.pagination.next_cursor })`.
   - **FlashList** (per 05-mobile-app §4.4 rule 6 — not FlatList/ScrollView) with `onEndReached` → `fetchNextPage`, `estimatedItemSize` set.
   - Pull-to-refresh via `RefreshControl` → `refetch()` (resets to first page).
   - Share card: thumbnail, place name + cuisine/price line, sharer + influencer attribution line, relative time; tap → `/place/[slug]`.
   - Empty state ("Nothing here yet — share your first reel") and error/retry; guests see the global feed (no auth wall).
5. **Mobile Search screen** (`app/search.tsx`, presented as modal per the router scaffold):
   - Debounced input (300 ms) → `useQuery(queryKeys.search(q, types), …, { enabled: q.length >= 2, placeholderData: keepPreviousData })` against `GET /search?q=&types=places,tags,influencers`.
   - Sectioned results (SectionList/FlashList sections): **Places** (name, city, cuisine → `/place/[slug]`), **Tags** (chip rows → places index filtered by tag; simple pushed list using `GET /places?tags[]=` is enough for M2), **Influencers** (handle + avatar; inert until M3 profiles — see T-033 gotcha pattern). `users` section omitted until M3.
   - States: idle (recent-searches placeholder optional), loading, empty ("No results for …"), error.
   - Entry points: search icon on Map and Feed headers → `router.push('/search')`.
6. **Mobile tests** (jest-expo + RNTL + msw, fixtures typed from `@reelmap/contracts`): feed renders fixture pages and appends on end-reached; pull-to-refresh resets to page one; card tap pushes the place route; search debounce fires one request for rapid typing; sections render per type; empty/error states.

## Acceptance criteria

- [ ] `GET /api/v1/feed` returns recent published shares (active places only), reverse-chron, cursor-paginated with `meta.pagination.next_cursor`
- [ ] `scope=following` is a validated, auth-required stub (empty data) whose response shape is final for M3
- [ ] Feed items embed sharer, influencer, source-post (original URL + thumbnail) and place summary; no N+1 (lazy-loading prevention in tests)
- [ ] Pest coverage: happy path, cursor walk stability, scope authz, exclusion of hidden/merged places, validation error envelope, rate-limit headers
- [ ] Feed screen uses FlashList with infinite scroll (`fetchNextPage` on end-reached) and pull-to-refresh
- [ ] Feed card tap navigates to `/place/[slug]`; attribution line shows sharer + influencer
- [ ] Search screen debounces input 300 ms, queries `GET /search`, renders sectioned Places / Tags / Influencers results
- [ ] Tag result tap shows places for that tag (`GET /places?tags[]=`)
- [ ] Empty, loading, and error/retry states implemented on both screens; global feed and search work unauthenticated
- [ ] Component tests green for pagination append, refresh reset, debounce single-request, section rendering, navigation

## Verification

```bash
cd apps/api && php artisan test --filter="Feed"
curl -s "http://localhost:8000/api/v1/feed?limit=2" | jq '.data[0].place.slug, .meta.pagination.next_cursor'
curl -s "http://localhost:8000/api/v1/feed?scope=following"   # → 401 without token

cd ../../apps/mobile
npx tsc --noEmit && npx eslint . && npx jest app/__tests__/feed app/__tests__/search
npx expo start --dev-client
```

Manual on device (seeded API with ≥ 30 published shares): Feed tab lists cards newest-first; scroll to bottom → next page appends without jump; pull down → spinner + refreshed list; tap card → place detail. Open search → type "nood" → places + tags sections appear after one debounced request (verify via network log); typo "nodle" still matches (Meilisearch).

## Gotchas

- **Cursor pagination + filters interplay:** a cursor encodes the ORDER BY position for a *specific* query — changing `scope` (or any future filter) while holding an old cursor must 422, not silently return wrong pages; key mobile infinite queries by `[scope]` so a scope switch starts a fresh pagination, never reusing cursors across scopes.
- **Feed ordering column:** order by `published_at` (nullable until published) not `created_at`, and always add the `id` tiebreaker or `cursorPaginate` produces dupes for same-second publishes.
- **Thumbnail URL expiry in infinite caches:** old pages hold presigned URLs; tolerate image load failures with placeholders (same as T-033).
- **Debounce + `keepPreviousData`:** without `keepPreviousData` the results flash empty on every keystroke; without `enabled: q.length >= 2` you hammer Meilisearch with 1-char queries.
- **FlashList measurement:** missing `estimatedItemSize` logs warnings and janks; variable-height cards need `overrideItemLayout` or consistent card heights.
- **Meilisearch in API tests:** feed tests don't touch search, but search-screen msw fixtures must mirror the real `GET /search` envelope from T-031 — regenerate contracts fixtures rather than hand-writing shapes.
