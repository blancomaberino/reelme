# T-140 — Three more screens `.map()` unbounded server collections into a ScrollView

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-039 (profile / influencer screens), T-042 (offers CRUD),
  T-037 (follows API + filters)
- **Target paths:** `apps/mobile/app/users/[username]/index.tsx`,
  `apps/mobile/app/restaurant/offers.tsx`,
  `apps/mobile/src/components/profile/follow-list.tsx`,
  `apps/mobile/src/api/hooks/useProfile.ts`,
  `apps/mobile/src/api/hooks/useOffers.ts`

Mobile review 2026-08-19, finding **MOB-10**.

## Context

| screen | fetches | renders |
|---|---|---|
| `users/[username]/index.tsx:170` | 50 (`useProfile.ts:56`) | `.map()` into a ScrollView, each a `MyPlaceCard` with a remote Thumbnail |
| `restaurant/offers.tsx:110-122` | 100 (`useOffers.ts:55`) | `.map()`, each an `OfferCard` with a quota meter and 3-4 buttons |
| `profile/follow-list.tsx:37` | 100 (`useProfile.ts:10`) | `.map()`, each a pressable |

**Failure:** tapping any user in search fires 50 concurrent image requests for
roughly 46 rows nobody has scrolled to; opening "Followers" on an active account
mounts 100 pressables synchronously, so the push animation drops frames.

The reviewer's note is the useful part: **`app/(main)/places.tsx` gets this
exactly right** — FlashList plus cursor pagination. The secondary screens did
not inherit it.

### Why this is separate from T-139

T-139 needs an API cursor, a contract change and a map-region cap. These three
need none of that — the fix is entirely client-side and independently
shippable. Splitting keeps a backend-and-contracts PR out of a three-screen
mobile PR.

## Implementation

Move all three to FlashList with the existing header as `ListHeaderComponent`.

And **decide about the caps.** 50 places and 100 followers are silent
truncations today: a user with 120 followers sees 100 and is told nothing.
Paginate, or show the cap. Do not leave it looking like the whole list.

## Acceptance criteria

- [ ] All three render through FlashList with the header as
      `ListHeaderComponent` — asserted on mounted rows for a 100-item fixture,
      not a snapshot
- [ ] Opening a public profile does not fire 50 concurrent image requests for
      rows nobody has scrolled to
- [ ] "Followers" on a 100-follower account no longer mounts 100 pressables
      synchronously; the push animation is measured on a device and recorded
- [ ] The fixed 50/100 caps are paginated, or documented as caps with the
      truncation visible to the user

## Gotchas

- A `ListHeaderComponent` that re-creates its element every render defeats the
  virtualization it was added for. Memoize it.
- `follow-list.tsx` is a component, not a screen, and is used from more than one
  place — check every consumer before changing its scrolling behaviour, or one
  of them ends up with a list inside a list.
