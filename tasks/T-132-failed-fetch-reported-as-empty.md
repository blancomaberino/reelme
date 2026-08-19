# T-132 — Five screens tell you that you have nothing when the request actually failed

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-062 (lists), T-071 (my-map / my-places), T-039 (profile &
  influencer screens)
- **Target paths:** `apps/mobile/app/users/[username]/map.tsx`,
  `apps/mobile/app/influencer/[id]/map.tsx`, `apps/mobile/app/lists/[id].tsx`,
  `apps/mobile/app/lists/index.tsx`, `apps/mobile/app/shares/index.tsx`

Code review 2026-08-19, findings **CR-11 (IMPORTANT)** and **CR-14 (MINOR)** —
the same defect on five screens, so one task.

## Context

`users/[username]/map.tsx:32` destructures `{ data, isLoading }`, drops
`isError`, and renders the empty state for `!region`.

`influencer/[id]/map.tsx:35` — near-identical otherwise — **has** the `isError`
branch, with the comment:

> a 422'd endpoint spent this screen's whole life telling people a creator had no
> places.

So: open a profile, tap "Ver mapa" while `GET /users/{username}/places` 5xxs or
times out, and the app asserts **as fact** that this person has no places, with
no retry. `git log` shows this exact class fixed **twice already** (#188, #190) —
and both times the fix landed on the influencer copy and not this one.

`lists/[id].tsx:33`, `lists/index.tsx:17` and `shares/index.tsx:22` take only
`{ data, isLoading }` too, while their public twin `list/[slug].tsx:60` handles
`isError`. None has a `RefreshControl`.

Profile → Listas on a flaky connection shows "this list is empty" with an
"Añadir un lugar" CTA — **your saved places look deleted**, and the only recovery
is leaving the screen and coming back. On `shares/index` the same shape makes an
in-flight share unreachable, and that screen is the documented re-entry point for
the review flow.

## Implementation

The reviewer is explicit, and it is the point of the task:

> **EXTRACT** the shared body of the two map screens rather than copying the
> isError branch across a third time.

A PR that adds four `isError` branches has reproduced the bug's cause and will be
reviewed as such. Two extractions are in scope:

- **one shared user-map body**, consumed by `users/[username]/map.tsx` and
  `influencer/[id]/map.tsx`;
- **one query-state surface** — loading / error+retry / empty — that the three
  list and share screens render through, with a `RefreshControl`.

## Acceptance criteria

- [ ] Each of the five screens shows an error state with a retry when its query
      **fails** — asserted per screen by failing the query, not by rendering the
      happy path
- [ ] The user map and the influencer map share ONE body; the `isError` branch
      exists exactly once
- [ ] The three list/share screens have a `RefreshControl`, and a pull genuinely
      re-queries
- [ ] The **empty** state is reachable only from a successful empty response —
      asserted, because conflating the two is the entire defect

## Gotchas

- CLAUDE.md's reachability rule applies to the retry control: a test must
  **press** it and assert the query re-ran. "It rendered an error" is not "you
  can recover".
- The two map screens are not identical — the influencer one has claim affordances
  the user one does not. Extract the body they share; do not force one screen to
  grow the other's chrome.
- `list/[slug].tsx` (the public twin) already gets this right. Read it before
  inventing a new shape.
