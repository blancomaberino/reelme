# T-139 — List detail renders every item twice, unvirtualized, off an endpoint with no cursor

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-062 (lists), T-063 (public/shareable lists),
  T-105 (ApiResponse envelope + generic KeysetPaginator)
- **Target paths:** `apps/mobile/app/lists/[id].tsx`,
  `apps/mobile/app/list/[slug].tsx`,
  `apps/mobile/src/components/map/place-marker.tsx`,
  `apps/api/app/Http/Resources/PlaceListDetailResource.php`,
  `apps/api/app/Http/Controllers/Api/V1/PlaceListController.php`,
  `packages/contracts/schemas/place-list-detail.json`

Mobile review 2026-08-19, finding **MOB-2 (BLOCKING)**.

## Context

List detail is **unbounded** — `PlaceListDetailResource::toArray` maps every
item, and there is no `limit` or cursor anywhere in the path — and
`lists/[id].tsx:145-172` renders the collection **twice** inside a plain
`ScrollView`:

- once as `<PlaceMarker … detailed>`, which mounts an `<Image>` and holds
  `tracksViewChanges` on until the remote photo loads
  (`place-marker.tsx:58-77`);
- once as a row.

The public twin, `list/[slug].tsx:67,96,113`, does the same.

**Failure:** a user who saved 200 places into one list taps it and gets 200
native marker views rasterising 200 remote images, plus 200 off-screen rows, all
before first paint — seconds of frozen white on a mid-range Android, a memory
spike on iOS. Every add/remove mutation returns the same full payload and
redraws it.

### This is not a mobile-only fix

The endpoint has no limit and no cursor, so virtualizing the client still ships
the whole collection over the wire. **T-105 already built the generic
KeysetPaginator** for exactly this; hand-rolling a second one here would be this
wave's root cause in miniature.

### Root cause, shared with T-140 and T-141

The core screens got the care and the secondary ones inherited none of it.
`app/(main)/places.tsx` is a properly virtualized, cursor-paginated FlashList —
the pattern exists, in this repo, written by the same team. **Read it before
designing anything.**

## Implementation

- Add `limit` + cursor to the list-detail endpoint using T-105's paginator;
  update `place-list-detail.json` and regenerate.
- Virtualize the rows with FlashList.
- Cap the map to the items in the fitted region rather than mounting a marker
  per item.
- Make add/remove mutations update the cache rather than redrawing the whole
  collection.

## Acceptance criteria

- [ ] The endpoint takes a limit and a cursor — T-105's paginator, not a second
      hand-rolled one — and the contract schema and generated types carry it
- [ ] The rows are virtualized; a 200-item list mounts a screenful, asserted on
      the number of rendered rows rather than a snapshot
- [ ] The map renders only the items in the fitted region
- [ ] An add/remove mutation no longer redraws the whole collection — asserted
      on what re-renders, not on the request count
- [ ] Measured on a device with a 200-item list: time to first paint recorded
      before and after, in the PR

## Gotchas

- **Do not rewrite `place-marker.tsx`.** Its discipline is genuinely good — the
  memo comparator is built from `Record<keyof MarkerPlace, true>` so the type
  cannot drift past it. The bug is how many get mounted, not what each one does.
- Both twins need the fix, and `list/[slug].tsx` is the public one — a
  regression there is visible to people who do not have the app.
- A cursor on an endpoint the app already calls is a breaking shape change if
  the envelope moves. Check T-105's envelope contract before changing the
  response.
