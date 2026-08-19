# T-119 — One user's extraction writes fabricated discounts and tags onto someone else's published place

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-023 (places + place_sources + ResolvePlace dedup),
  T-079 (card-specific discounts: extract, surface, filter)
- **Target paths:** `apps/api/app/Services/Places/PlaceResolver.php`,
  `apps/api/app/Services/Places/TagMaterializer.php`,
  `apps/api/app/Http/Resources/PlaceResource.php`,
  `apps/api/tests/Feature/Places/`

Security review 2026-08-19, finding **SEC-4 (MEDIUM, CWE-284)**.

## Context

`PlaceResolver.php:387` — `attach()` — writes the corrected
`extraction_snapshot_json` as a `place_source` on whichever place resolved,
**including an existing, published one**, and that content is served on the
public, unauthenticated `GET /places/{place}`.

Through the same `PATCH /shares/{id}` seam as T-117, one user posts an invented
"50% off with Santander" or fabricated prices onto a competitor's page.
`TagMaterializer.php:95` additionally backfills `cuisine_primary` when it is
null — narrower, still attacker-chosen.

**This is not closed by T-117's fix**, which is why the three trust-boundary
findings are filed apart. T-117 stops you becoming the venue's operator. This
stops you writing on its page while being nobody at all.

## Implementation

The design question *is* the task: what makes a second user's claim about an
already-published place trustworthy enough to serve to the public?

- **Corroboration is the reviewer's answer and it matches how the rest of the
  system already reasons.** T-082 (multi-source review aggregation) and T-079
  (card discounts extracted from captions) both already deal in *more than one
  source said so*. Reuse that vocabulary rather than inventing a per-field trust
  flag that will only ever be used here.
- **One definition, two call sites.** The discount path and the tag path must
  consume the same rule. Two rules that agree today are the shape this whole
  audit wave is about.
- **Nothing is silently dropped.** The un-corroborated `place_source` row should
  still exist and still be visible to a moderator — the finding is that it is
  *served*, not that it is *stored*.

### Be precise about scope

Community data on an unclaimed pin **is the product**. A stranger writing a
price or a discount onto a claimed, published venue is not. If those two cases
need different rules, say so and test both — do not pick one and hope the other
never comes up.

## Acceptance criteria

- [ ] An extraction resolving onto an already-`active` place cannot put discount
      content on the public `GET /places/{place}` without corroboration — proven
      by attaching a fabricated discount through `PATCH /shares/{id}` and
      asserting its absence from the unauthenticated payload
- [ ] `cuisine_primary` is not backfilled from a single un-corroborated
      extraction
- [ ] Corroboration has ONE definition used by both the discount and tag paths
- [ ] The un-corroborated source row still exists and is visible to a moderator
- [ ] The ordinary path is unharmed: a first source on a pending place still
      publishes end to end (the existing pipeline test)

## Gotchas

- Do not solve this by refusing to attach to published places at all — that
  breaks the ordinary case where a second person legitimately shares a reel
  about a place someone else added first. That case is the product working.
- `attach()` sits inside the resolver's transaction. A corroboration check that
  does I/O belongs outside it.
