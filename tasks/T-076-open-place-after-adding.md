# T-076 — Open the place right after adding it

- **Phase:** M2 (mobile UX) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-013 (share→publish), T-071 (personal collection) · **Related:** T-077 (the add entry point moves to the map quick-add)
- **Target paths:** `apps/mobile/app/(main)/map.tsx` (quick-add `onQuickPublished`), `apps/mobile/src/components/map/quick-share.tsx`, `apps/mobile/app/(main)/share.tsx` (share result)
- **UI task → use the `/frontend-design` skill.**

## Context

Requested 2026-07-16. After you add a place (paste a link → it resolves &
publishes), the app leaves you on the result/map. You want to **land on the place
you just added** — open its detail screen so you can see/annotate it immediately.

Today: the **share** flow shows a result card in place, and the **map quick-add**
(`QuickShareModal`) only `animateToRegion`s the map to the new pin. Neither routes
to the place.

## Implementation

- On a successful **single-place** publish, navigate to `/place/[slug]` for the
  new place (both the share-tab flow and the map quick-add's `onPublished`).
- **Multi-place** post (T-013): don't hide the others — route to the **primary**
  place, or keep the result list with each venue tapping through to its detail.
  Decide per the design; the single-place case is the headline ask.
- Keep it non-jarring: the publish confirmation should read as "here's your place"
  rather than an abrupt jump — follow `/frontend-design`.

## Acceptance criteria

- [ ] After a single-place add (share tab AND map quick-add), the app opens that
      place's detail (`/place/[slug]`)
- [ ] A multi-place add routes to the primary place (or a tap-through result), no
      venue lost
- [ ] Works from both entry points; no dead-end if publish fails (stays on the
      form with the existing error UX)
- [ ] Test: a mocked publish drives navigation to the place route with the right slug
- [ ] Gates: mobile eslint + tsc + jest

## Log

- **2026-07-16** — Built. Map quick-add (`onQuickPublished`) now pushes
  `/place/[slug]` (primary place) after the fly-to; share tab (`ShareProgress`)
  auto-opens the detail on a single clean publish (ref-latched), while
  multi-place/partial keep the result card so no venue is lost. Tests cover all
  four branches. Gates green (jest 204, tsc, eslint). /coderabbit clean.
  **PR #95 open** (awaiting merge).

- **2026-07-16 (merged)** — squash-merged to main; task done.
