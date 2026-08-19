# T-122 — Blocking is not applied to reviews or search

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-059 (native reviews: API, aggregation, moderation),
  T-031 (tags + Scout/Meilisearch search)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/ReviewController.php`,
  `apps/api/app/Http/Controllers/Api/V1/PlaceController.php`,
  `apps/api/app/Services/Search/SearchService.php`,
  `apps/api/tests/Feature/Reviews/`

Security review 2026-08-19, finding **SEC-9 (MEDIUM, CWE-284)**.

## Context

`ReviewController.php:36` runs `$place->reviews()->visible()->with('user')` with
no block filter. The same gap exists in the `PlaceController` reviews embed and
in `SearchService.php:53`.

So `GET /places/{id}/reviews` hands a blocked account's review body, username,
real name and avatar to the person who blocked them.

What makes this unambiguous rather than a judgement call is the contrast:
`FeedController.php:60` applies `invisibleTo()` **with a comment explaining why
the filter has to cover the global scope too**. The rule was written down. Native
reviews (T-059) shipped later and were simply never wired in.

### Scope it honestly

This is a **safety-control bypass**, not a private-data leak — a review is public
content. It matters because blocking is an Apple 1.2 UGC requirement and T-054
(store readiness) rests on it working, and because a person who blocked someone
reasonably expects not to read them.

## Implementation

The one-line version is:

```php
->whereNotIn('reviews.user_id', app(BlockUsers::class)->invisibleTo($request->user('sanctum')?->id))
```

**Do not paste it three times.** T-054's own notes record paying for exactly that
once already:

> A RULE WITH TWO IMPLEMENTATIONS, TWICE IN ONE FEATURE. The profile privacy gate
> lived in BOTH `ProfileController::assertViewable()` and
> `ProfilePlacesRequest::authorize()`, so blocking was added to one and
> `/users/{u}/places` stayed readable by a blocked account. [...] Extracted to
> `App\Support\Profiles\ProfileVisibility`.

Find the seam that covers reviews-direct, reviews-embedded and search at once —
most likely on the reviews relation/scope itself — or state in the PR why three
call sites genuinely cannot share one.

## Acceptance criteria

- [ ] `GET /places/{id}/reviews` omits a blocked account's review, in **both**
      directions of the block
- [ ] The `?include=reviews` embed applies the same filter, asserted
      **separately** — it is a second copy of the rule, and that is how this gap
      happened
- [ ] Search results exclude blocked accounts
- [ ] The filter reaches the global scope the way `FeedController`'s comment
      describes; the test proves the scope path, not just a direct query
- [ ] An unauthenticated request still returns reviews and is unfiltered

## Gotchas

- `Sanctum::actingAs`, not `$this->actingAs`, when the code reads
  `$request->user('sanctum')` — T-054's trap (c), and the guest and the blocked
  stranger otherwise look identical in a test.
- Aggregates: if a blocked user's rating still counts toward the place's average,
  the review disappears but its star does not. Decide, and say which.
