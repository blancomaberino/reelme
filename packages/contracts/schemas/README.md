# schemas/

Per-resource JSON Schemas for the API payloads (01-architecture §6). Each file is the
single source of truth for one response shape: the Laravel side validates live resource
output against it (`ApiSchema::validate()`, in contract tests), and the mobile side codes
to the TS type generated from it (`src/generated/`, re-exported from `src/index.ts`).

| Schema | Resource | Endpoint |
| --- | --- | --- |
| `place.json` | `PlaceResource` | `GET /places/{slug}` |
| `place-summary.json` | `PlaceSummaryResource` | `GET /places`, map, feed, list items |
| `place-source.json` | `PlaceSourceResource` | `GET /places/{slug}/sources` |
| `place-list.json` | `PlaceListResource` | `GET /me/lists` |
| `place-list-detail.json` | `PlaceListDetailResource` | `GET /me/lists/{id}`, `GET /lists/{public_slug}` |
| `share.json` | `ShareResource` | `GET /shares/{id}` |
| `feed-item.json` | `FeedItemResource` | `GET /feed` |
| `user-profile.json` | `PublicUserResource` | `GET /users/{username}` |
| `user-summary.json` | `UserSummaryResource` | embedded attribution block |
| `influencer-profile.json` | `InfluencerResource` | `GET /influencers/{handle}` |
| `influencer-claim.json` | `InfluencerClaimResource` | `POST /influencers/{handle}/claim` |

Conventions:

- `$id` is `https://contracts.reelmap.app/schemas/<file>.json`; cross-file `$ref`s are
  written **relative** (`user-summary.json`, `../extraction.schema.json`) so the PHP
  validator and the TS generator both resolve them from the file's own location.
- `additionalProperties: false` everywhere — an unlisted field is drift, not a feature.
- Nullable embeds are `anyOf: [{ "type": "null" }, { "$ref": … }]`; that's how draft-07
  expresses "null or this schema".
- Editing a schema means regenerating (`npm run generate -w packages/contracts`, or just
  save — the repo hook does it); the committed output in `src/generated/` is drift-checked.

The **canonical AI extraction contract lives one level up** at
[`../extraction.schema.json`](../extraction.schema.json) — it predates this directory
(T-005) and is referenced by path from the API (T-021) and the ROADMAP exit criteria, so
it stays where it is. `share.json` `$ref`s it for `analysis.extraction`.
