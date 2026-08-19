# T-131 — The map's quick-share is a second-class copy of the composer

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-025 (share-intent receiver + share screen), T-051 (quotas &
  rate limits), T-071 (my-map / my-places collection)
- **Target paths:** `apps/mobile/src/api/hooks/useShareStatus.ts`,
  `apps/mobile/src/api/hooks/useCreateShare.ts`,
  `apps/mobile/src/components/map/quick-share.tsx`,
  `apps/mobile/app/(main)/share.tsx`

Code review 2026-08-19, findings **CR-3 (BLOCKING)** and **CR-12 (IMPORTANT)**.
One task, because they are two symptoms of one thing: **the share-submit path
exists twice, and the newer copy is the worse one.**

## Context

### CR-3 — the primary entry point does not refresh the map

`useCreateShare.ts:34` invalidates only `quotas()`. `useShareStatus` polls to
`published` and invalidates **nothing**.

Every other path that puts a pin on the map does invalidate `mapAll()` +
`myPlacesAll()`: `usePublishBestGuess:29`, `useUpdateShare:32`,
`usePendingVenue:15`, `useSuggestEdit:39`, `useLists:117`, `useRemoveFromMap:46`.
Six correct copies and one miss — and the miss is the plain happy path, the one
`share.tsx:57` itself calls *"the product's PRIMARY entry point"*.

Share a reel through the OS share sheet, watch it publish, tap back to Map or Mis
lugares: `useMapPlaces` has `staleTime` 120 000, `useMyPlaces` 30 000, both tabs
stay mounted, and `focusManager` only fires on app foreground. The place the user
just added is **absent for up to two minutes**, with no way to force it but
backgrounding the app.

### CR-12 — the map's "+" drops the quota guard

`quick-share.tsx:57` — `QuickShareModal.submit` calls `create.mutate` with no
quota check and renders a flat `t('share.submitError')` for every error.

`share.tsx:93` guards on `quotas.shares.remaining` **before** the call, and
branches on `code === 'daily_quota_exceeded'` to say "resets at HH:MM" — with a
comment explaining that the guard must live in the submit path *precisely so a
generic error cannot stand in for the limit*.

So a user out of today's allowance taps "+", pastes a link, and gets "No se pudo
enviar": a transient-looking error inviting a retry that can never work.

## Implementation

**The fix is extraction, not a seventh copy.** Adding the invalidation list to
`useShareStatus` and pasting the quota branch into `quick-share.tsx` would make
both bugs go away and leave the mechanism that produced them completely intact.

- **One "a pin just landed" invalidation**, declared once, consumed by all seven
  call sites. Adding an eighth must not be able to forget one.
- **One share-submit guard**, owning both the pre-call quota check and the
  `daily_quota_exceeded` copy, consumed by both entry points.

## Acceptance criteria

- [ ] A share that publishes puts the pin on the map and in Mis lugares without
      backgrounding the app — asserted on `useShareStatus` observing `published`,
      **and walked on the simulator through the OS share sheet**
- [ ] ONE declaration of what to invalidate when a pin lands; all call sites use
      it
- [ ] A user out of allowance gets the "resets at HH:MM" message from the map's
      "+" as well as the share screen, from one shared guard
- [ ] A test drives the map's "+" submit path, not only the composer
- [ ] The `daily_quota_exceeded` branch is not duplicated

## Gotchas

- The share-sheet path needs a **physical device or the share extension** to walk
  properly; say so honestly in the completion report if a step could not be
  reached, rather than describing a path nobody walked.
- **File overlap:** `quick-share.tsx` is also touched by T-133 (its chrome is one
  of the four hand-rolled sheets). No dependency — whichever lands second
  rebases.
- T-051's ADR-051 explains why `daily_quota_exceeded` is its own code and not
  `rate_limited`. Keep that distinction visible in the shared guard; collapsing
  them is the bug the code comment was written to prevent.
