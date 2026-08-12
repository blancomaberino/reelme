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

## Decisions (owner, 2026-07-29) — settled, do not re-litigate

1. **Country is PUBLIC when the profile is public.** It mirrors `bio`: coarse
   (country, never city) and it is what makes regional discovery possible. So it
   goes into `packages/contracts/schemas/user-profile.json`, and a private profile
   must not leak it.

2. **Store the code, serve the name.** `country_code` (ISO 3166-1 alpha-2) is the
   stored + exchanged value; the API additionally emits a localized
   `country_name` for display.

### What decision 2 settles

Name resolution happens **server-side**, reusing the ADR-084 localization path the
tag labels already use — `RequestLocale::resolve()` (`?locale=` → `Accept-Language`
→ default; supported `es`/`en`), and the mobile client already sends
`Accept-Language` on every request (`src/api/client.ts`).

**This removes the old open question about `Intl.DisplayNames` / Hermes ICU
entirely — the mobile app never needs a country dataset.**

Verified in the Sail container on 2026-07-29:

- `intl` **is** loaded; `Locale::getDisplayRegion('-UY', 'es')` → `Uruguay`,
  `('-ES','es')` → `España`, `('-US','es')` → `Estados Unidos`. Good for both
  supported locales.
- ⚠️ **`getDisplayRegion()` never errors** — it echoes unknown input (`QQ` → `"QQ"`,
  `U1` → `"U1"`) and returns `"Región desconocida"` for `ZZ`. It is a **display**
  function only. Validation MUST use an explicit ISO 3166-1 alpha-2 allow-list
  bundled in the API; do not infer validity from this call, and do not copy
  Filament's loose `maxLength(2)`.
- Iterating `ResourceBundle::create('en','ICUDATA-region')` yielded only 4 entries,
  so it is **not** a usable source for the canonical list — bundle the ~250-code
  list explicitly (a constant/data file, same shape as
  `database/seeders/data/tag_es_translations.php`).

### Still open (implementation detail, decide in the PR)

- **Country catalog for the picker.** The mobile picker needs ~250 `{code, name}`
  pairs. Preferred: a small `GET /api/v1/countries` returning the localized
  catalog (one source of truth, cacheable, same `RequestLocale` path, and it also
  feeds the my-places chip names). Alternative: ship the codes in the client and
  take names from the profile payload only — cheaper, but leaves the picker
  unlocalized. Recommend the endpoint.
- **Registration/onboarding.** Out of scope here — this task is profile *editing*,
  as requested. File a follow-up if wanted.

## Implementation

- Migration: `users.country_code` `char(2)` **nullable** (existing users have no
  value and must not be forced into one), plus an index if regional queries land.
- `PATCH /me` accepts `country_code`; validate against the explicit ISO 3166-1
  alpha-2 allow-list, normalize to uppercase, and allow `null` to clear it.
- `GET /me` and the public profile resource return **both** `country_code` and the
  localized `country_name` (decision 2), the latter resolved through
  `RequestLocale` + `Locale::getDisplayRegion()`. Public exposure per decision (1).
- Extend `packages/contracts/schemas/user-profile.json` + regenerate TS. Coordinate
  with **T-102**, which is adding contract coverage — same files.
- Mobile: a searchable country picker on `app/profile/edit.tsx` (a 250-row list
  needs search, not a raw wheel). Follow the existing `TextField`/sheet patterns;
  use `/frontend-design` per CLAUDE.md. **No bundled country dataset on the
  client** — names come from the API.
- Point `my-places-filters` at the same localized names so the facet chips stop
  showing raw ISO codes.
- Filament: surface + edit `country_code` on the Users resource, consistent with
  how the Places resource shows it.

## Acceptance criteria

- [ ] `users.country_code` exists as nullable `char(2)`, matching the
      `places.country_code` convention; existing rows are unaffected
- [ ] `PATCH /me` sets, changes, and clears (`null`) the country; an invalid or
      non-ISO code (e.g. `ZZ`, `USA`, `u1`) is rejected 422 with a field error,
      and lowercase input is normalized to uppercase
- [ ] `GET /me` returns the caller's country
- [ ] Country is exposed on a PUBLIC profile (decision 1) and the `user-profile`
      contract + generated TS agree with the API response (contract test, not just
      a 200 assertion)
- [ ] Responses carry both `country_code` and a localized `country_name`
      (decision 2); the name follows `Accept-Language` — the same payload returns
      "Spain" for `en` and "España" for `es`
- [ ] A private profile does not leak the country to other viewers
- [ ] Mobile edit-profile has a searchable country picker showing **localized
      names** in both es and en; selecting persists and survives an app restart;
      clearing it works. No country name/dataset is hardcoded in the client
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
- **2026-07-29** — Owner settled both open questions: (1) public when the profile
  is public; (2) store the code, serve the name. Verified `intl` is available in
  the container and that `getDisplayRegion()` is display-only (never errors on a
  bogus code), so validation needs an explicit allow-list. The Hermes/ICU question
  is closed — names are resolved server-side.
- **2026-08-12** — **DONE.** Merged to `main` as `18b836b` (PR #191). Both owner
  decisions implemented as settled; the open PR-level question resolved to
  `GET /api/v1/countries` (one localized source feeds the picker, the my-places
  chips and every `country_name`, so the app ships no country dataset).
  Validation uses a bundled 249-code table because `getDisplayRegion()` never
  fails — it echoes unknown input and is display-only. Scope additions worth
  recording: the country also RENDERS on the public profile (it was exposed by
  the API and promised by the edit screen's hint, and displayed nowhere), and
  GDPR export/erasure were both missing the new column.
  Follow-ups recorded in the app repo's `.claude/state/HANDOFF.md`: `places`
  still validates its country with a loose `maxLength(2)` (needs an `XX`
  sentinel decision + backfill before it can adopt the allow-list), plus the
  deferred #190 marker findings and several extraction candidates.
