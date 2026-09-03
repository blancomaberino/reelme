# T-157 — Dishes become a first-class, queryable thing instead of buried JSON

- **Phase:** GROW · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** nothing
- **Target paths:**
  `apps/api/database/migrations/`,
  `apps/api/app/Models/Dish.php`,
  `apps/api/app/Jobs/PublishShare.php`,
  `apps/api/app/Services/Places/PlaceAggregations.php`,
  `apps/api/app/Http/Requests/PlaceListingRequest.php`,
  `apps/api/app/Models/Builders/PlaceQueryBuilder.php`,
  `apps/api/app/Console/Commands/`

## Why

The owner's example query is "I want pasta -- which five places near me do
pasta?" Nothing in the system can answer it. `cuisine_primary` yields `italian`;
the 23 vibe chips describe the room, not the food.

The data is already extracted and already validated. `extraction.schema.json`
captures `dishes[]{name, shown_in_video, price}` per place, and the pipeline
writes it into `place_sources.extraction_snapshot_json` -- a JSONB blob that
`PlaceAggregations` re-parses in PHP on every read and that no index can reach.

Promoting dishes to their own table is one migration and one job at an existing
seam. It is also the precondition for Tonight (T-158), for a conversational
surface later, and for the long-tail web pages ("mejor milanesa napolitana en
Montevideo") that a place-name-only page can never rank for.

## Acceptance

- A `dishes` row set is written at publish from the extraction snapshot, keyed to the place and the source that claimed it
- `GET /places?dish=pasta` returns places with a matching dish and excludes places without one; matching is case- and accent-insensitive (unaccent/citext are already enabled)
- A place whose extraction carried no dishes produces no rows and is never returned by a dish query -- asserted, so the filter cannot silently degrade to 'everything'
- `PlaceAggregations` reads the table instead of re-parsing JSONB, with a test that asserts identical output before and after the switch
- A backfill command populates existing places and is idempotent: running it twice produces the same row count
- Dish text is treated as untrusted model output -- length-capped and never rendered as markup

## Notes

Filed 2026-09-03. Promoted from Wave 3 to a Wave 1 dependency by the owner's product model (08 section 9.1): the core retrieval query is dish-shaped, not cuisine-shaped. pgvector/embeddings are explicitly NOT in scope here -- the plain table answers 'who does pasta'; semantic search is a later task built on this one.
