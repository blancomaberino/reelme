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
