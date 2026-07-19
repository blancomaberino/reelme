# T-077 — Replace the "Compartir" tab with a Search tab (find people & places)

- **Phase:** M2 (mobile UX) · **Estimate:** S–M · **Status:** see tasks.json
- **Depends on:** T-071 (personal collection), T-039 (profiles) · **Related:** T-076 (adding now happens via the map quick-add)
- **Target paths:** `apps/mobile/app/(main)/_layout.tsx`, `apps/mobile/app/search.tsx`, `apps/mobile/src/api/hooks/useSearch.ts`, `apps/api` search endpoint (people), `apps/mobile/src/i18n/{en,es}.ts`
- **UI task → use the `/frontend-design` skill.**

## Context

Requested 2026-07-16. The bottom tab bar is Mapa · Mis lugares · **Compartir**
(the center "+" tab, `share.tsx`) · Perfil. The **Compartir** tab "doesn't make
sense" — it duplicates the map's own quick-add "+" (`QuickShareModal`). Replace it
with a **Buscar** (Search) tab for finding **people or places**.

A search screen already exists (`app/search.tsx` + `useSearch`), reached today
from the map/places headers, with sections Lugares / Etiquetas / Influencers and
a "Profiles coming soon" placeholder — so **people/profile search is a known TODO**
this task should finish.

## Implementation

- **Tabs:** swap the `share` tab for a `search` tab (search icon, `tabs.search`
  label). Adding a place stays fully reachable via the map header "+" quick-add —
  verify that path still works and is discoverable before removing the tab.
- **Search screen:** promote it to a tab destination; extend it to **people**
  (users/profiles → tap opens `/users/[username]`), replacing the "Profiles coming
  soon" stub. Keep places/tags; influencers vs. users may merge into one "People"
  section per design.
- **Backend:** confirm/extend the search endpoint to return users (by
  username/name), respecting the private-profile rules. If the API has no people
  search yet, add it (owner-scoped/public-profile-safe).
- Remove the now-dead `share.tsx` tab route cleanly (no orphaned nav / i18n);
  decide whether the share *flow* screen is kept as a pushed route or fully folded
  into the map quick-add.

## Acceptance criteria

- [ ] Bottom tab bar shows **Buscar** (search icon) where **Compartir** was
- [ ] The Search tab finds **people** (→ profile) AND places (+ tags); no "coming
      soon" stub left
- [ ] Adding a place is still reachable and obvious via the map quick-add "+"
- [ ] No dead route / orphaned i18n from the removed tab
- [ ] Backend people-search is private-profile-safe (no existence oracle)
- [ ] Tests: tab renders Search not Share; search returns people+places; API people-search test
- [ ] Gates: API Pint/PHPStan/Pest (if backend touched); mobile eslint + tsc + jest

## Log

- **2026-07-16** — Built. Backend: User is Scout-searchable (username/name/bio),
  shouldBeSearchable gates on is_public; SearchService/Controller/scout/reindex
  handle the `users` type; hydrate filters is_public=true (belt over the index).
  Privacy proven on collection AND real-Meili engines incl. a stale-index case.
  Mobile: Buscar tab replaces Compartir (search.tsx moved into (main); Share kept
  as href:null route = iOS share-intent target); search finds People →
  /users/[username] + Places + Tags; influencer "coming soon" stub removed. Gates:
  API Pint+PHPStan+568 Pest; mobile eslint+tsc+201 jest. /coderabbit clean (2
  parallel review agents; the one should-fix — untested prod privacy path — fixed).
  **PR #96 open** (awaiting merge). Deliberate: influencer results dropped from
  search UI (inert; return with T-038); is_public default-true posture flagged.

- **2026-07-16 (merged)** — squash-merged to main; task done.
