# T-167 — The public web surface: creator maps and place pages that a link can reach

- **Phase:** GROW · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-163
- **Target paths:**
  `apps/api/routes/web.php`,
  `apps/api/resources/views/`,
  `apps/api/app/Http/Controllers/`

## Why

Every read endpoint this needs is already public and unauthenticated --
`/places`, `/map/places`, `/users/{username}`, `/influencers/{id}/places`,
`/search`. What is missing is a renderer. Public influencer maps are built and
have no web page at all, so a creator has nothing to link to.

`reelmap.app/@handle` is three things at once: the creator's link-in-bio (a real
map of their recommendations, each pin linking back to their original video), the
indexable surface, and the page a claim flow can land on.

Reverses the "web app out of scope" line in 00-product-spec section 6.1 -- five of
six reviewers independently made this their top or second recommendation.

## Acceptance

- `/@handle` and place pages are server-rendered from the existing public endpoints, with content present in the HTML before any JavaScript runs
- No second map implementation: the existing renderer is extracted and reused, not re-written (CLAUDE.md sibling-first)
- Hidden, unpublished, private and blocked-user content never appears, asserted per case
- Per-page OG tags and image; a sitemap route that lists only publicly visible pages
- Each pin links out to the original source post, and an unavailable source is labeled rather than broken (FR-30)
- The page is usable without the app and never gates content behind an install prompt

## Notes

Filed 2026-09-03. Depends on T-163 for the link and OG plumbing. Explicitly reverses 00-product-spec section 6.1; record as an ADR in 07-risks-decisions.md when it lands.
