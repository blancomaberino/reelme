# T-110 — Users can set their country on their profile

- **Phase:** M1 (profile) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-036 (public profiles API), T-039 (mobile profile/settings screens)
- **Target paths:**
  - `apps/api/database/migrations/`
  - `apps/api/app/Models/User.php`
  - `apps/api/app/Http/Controllers/Api/V1/MeController.php`
  - `apps/api/app/Http/Requests/` (the /me update request)
  - `apps/api/app/Http/Resources/UserProfileResource.php`
  - `apps/api/app/Filament/Resources/Users/`
  - `packages/contracts/schemas/user-profile.json`
  - `apps/mobile/app/profile/edit.tsx`
  - `apps/mobile/src/lib/` (shared country helper)
  - `apps/mobile/src/components/place/my-places-filters.tsx`

## Context (owner request, 2026-07-29)

There is currently **no way for a user to say where they are**. `users` has
`name`, `username`, `bio`, `avatar_path`, `birthdate`, `favorite_topics`,
`favorite_foods`, `is_public` — and no country or location column at all. The
`user-profile` contract has no country field, and `app/profile/edit.tsx` exposes
only name / bio / birthdate / topics / foods.

Every existing "country" in the codebase is about a **place**
(`places.country_code`), not a person.

### Why it is worth having

- **Regional discovery** — the feed and search have no notion of where the viewer
  is, so a Montevideo user and a Madrid user get an identically-scoped experience.
- **A better map fallback than a hardcoded city.** T-100 resolves the opening
  viewport as param → saved viewport → device location → `DEFAULT_REGION`, and
  that last rung is Montevideo for everyone on the planet. A stored country gives
  a genuinely personal fallback when location is denied or no fix arrives. See
  "Follow-on" below — worth doing, but keep it out of this task's scope.
- **Currency default** — `useSettingsStore` already carries a currency glyph the
  user must pick by hand; country implies a sensible default.
- **Monetization/tax later** — M4 Stripe Connect onboarding will need a country
  anyway.

## Existing conventions to reuse (do not reinvent)

- `places.country_code` is `char(2)`, ISO 3166-1 alpha-2, indexed as
  `['country_code', 'city']`. Match that type and casing exactly so places and
  users can be compared/joined without normalization.
- Filament's place form uses `TextInput->maxLength(2)` — a **loose** check that
  accepts `ZZ`. Do not copy it; validate against a real ISO list (see below).
- There is **no country-name helper anywhere in the repo**. `my-places-filters`
  renders the raw code, so the filter chips currently read "UY" rather than
  "Uruguay". A shared helper added here should fix that too.

## Open questions to settle before/while implementing

1. **Is a user's country public?** Profiles already have an `is_public` toggle
   (T-039). Recommendation: treat country as **public when the profile is
   public**, mirroring `bio` — it is coarse (country, never city) and it is what
   makes regional discovery possible. If it should be private-to-`/me` instead,
   it must stay out of `user-profile.json`. **Decide explicitly and record it in
   the PR**, because moving it later is a contract break.
2. **Where do localized country names come from?** ~250 names × es/en does not
   belong in the flat `src/i18n/*.ts` dictionaries. Options, in preference order:
   (a) `Intl.DisplayNames` — **verify Hermes on RN 0.86 ships full ICU** before
   committing to it; the app already depends on the OS locale via `useColorScheme`
   /`useSettingsStore`, so this is the cheapest if available. (b) A small bundled
   `code → {es, en}` dataset (~15 KB). Do NOT pull a heavyweight i18n library in.
3. **Should this also appear at registration/onboarding?** Out of scope here —
   this task is profile *editing*, as requested. File a follow-up if wanted.

## Implementation

- Migration: `users.country_code` `char(2)` **nullable** (existing users have no
  value and must not be forced into one), plus an index if regional queries land.
- `PATCH /me` accepts `country_code`; validate against an explicit ISO 3166-1
  alpha-2 allow-list, normalize to uppercase, and allow `null` to clear it.
- `GET /me` returns it. Public profile resource includes it per decision (1).
- Extend `packages/contracts/schemas/user-profile.json` + regenerate TS. Coordinate
  with **T-102**, which is adding contract coverage — same files.
- Mobile: a searchable country picker on `app/profile/edit.tsx` (a 250-row list
  needs search, not a raw wheel). Follow the existing `TextField`/sheet patterns;
  use `/frontend-design` per CLAUDE.md.
- Shared `countryName(code, locale)` helper in `apps/mobile/src/lib/`; point
  `my-places-filters` at it so the facet chips stop showing raw ISO codes.
- Filament: surface + edit `country_code` on the Users resource, consistent with
  how the Places resource shows it.

## Acceptance criteria

- [ ] `users.country_code` exists as nullable `char(2)`, matching the
      `places.country_code` convention; existing rows are unaffected
- [ ] `PATCH /me` sets, changes, and clears (`null`) the country; an invalid or
      non-ISO code (e.g. `ZZ`, `USA`, `u1`) is rejected 422 with a field error,
      and lowercase input is normalized to uppercase
- [ ] `GET /me` returns the caller's country
- [ ] Public profile exposure matches the decision recorded for open question (1),
      and the `user-profile` contract + generated TS agree with the API response
      (contract test, not just a 200 assertion)
- [ ] A private profile does not leak the country to other viewers
- [ ] Mobile edit-profile has a searchable country picker showing **localized
      names** in both es and en; selecting persists and survives an app restart;
      clearing it works
- [ ] `my-places` filter chips show localized country names instead of raw ISO
      codes, via the shared helper
- [ ] Filament Users resource shows and edits the country
- [ ] Tests cover: valid set, clear-to-null, invalid-code rejection, case
      normalization, private-profile non-leak, and the mobile picker select/persist
- [ ] Gates: `composer lint` + `stan` + `test`; `npm run lint` + `tsc --noEmit` +
      `test`; coverage not regressed

## Follow-on (not this task)

Once `users.country_code` exists, T-100's last-resort `DEFAULT_REGION` (currently
Montevideo for every user on earth) can fall back to the user's country instead.
File as a small follow-up against `src/lib/initial-region.ts` — it needs a
country → centroid/bbox source, which is its own decision.

## Log

- **2026-07-29** — Filed at the owner's request after confirming no existing task
  covered it (all `country` references in the plan and code are about places).
