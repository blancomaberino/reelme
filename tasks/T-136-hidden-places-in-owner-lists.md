# T-136 — A moderator-hidden place stays in your list, reports an impossible status, and taps through to a 404

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-062 (lists), T-049 (moderation: reports, takedown)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/PlaceListController.php`,
  `packages/contracts/schemas/place-summary.json`,
  `apps/api/tests/Feature/Lists/`

Code review 2026-08-19, finding **CR-10 (IMPORTANT)**.

## Context

`PlaceListController.php:164` — `loadWithPlaces`, used by `show`, `addPlace` and
`removePlace` — has **no `publiclyVisible()`**. Its sibling at `:179`,
`loadWithVisiblePlaces`, does, and its docblock explains why. `PlaceModerator`
never removes the row from `place_list_items`.

The cascade is what makes this worth a task rather than a line:

- `GET /me/lists/{id}` returns `status: "hidden"`;
- `place-summary.json:16` declares the enum as `["pending","active"]`, and the
  generated TS narrows to that union;
- so the app happily renders the row, and tapping it hits `/place/[slug]`, which
  **404s** at `PlaceController.php:160`.

A permanently dead row in the user's own list, carrying a status the contract says
cannot exist.

## Implementation

Two halves, and fixing only the first is the trap:

1. apply `publiclyVisible()` in `loadWithPlaces` the way `loadWithVisiblePlaces`
   does;
2. assert that **no response can emit a status outside the enum**.

The second is what stops the next status value — or the next un-scoped loader —
doing this again silently. The contract already declared the truth; nothing was
checking.

## Acceptance criteria

- [ ] `GET /me/lists/{id}` omits a moderator-hidden place — asserted for `show`,
      `addPlace` **and** `removePlace`, since all three use `loadWithPlaces`
- [ ] No response can carry a `status` outside `place-summary.json`'s enum,
      asserted against the schema
- [ ] Adding a hidden place to a list is refused rather than silently stored
- [ ] Removing a place that has since been hidden **still works**

## Gotchas

- **Watch `removePlace`.** A user whose list contains a place that was hidden
  after they saved it must still be able to remove it; a visibility filter that
  hides the row from the delete lookup strands it forever. Test that case
  explicitly.
- The list's item **count** is a second surface: if the hidden place is filtered
  from the payload but still counted, the list reads "3 lugares" over two rows.
- ADR-085 records that a take-down is `places.status`, not a delete. That is the
  right design — this task is about the surfaces that never learned to read it.
