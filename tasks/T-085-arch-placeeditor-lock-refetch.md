# T-085 — ARCH/P0: PlaceEditor.apply() lock+refetch before diffing locked_fields

- **Phase:** ARCH (P0 correctness) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-084 (places-as-businesses: enrichment + PlaceEditor)
- **Target paths:** `apps/api/app/Services/Places/PlaceEditor.php`,
  `apps/api/app/Services/Places/Enrichment/BusinessEnricher.php`,
  `apps/api/app/Filament/Resources/Places/Concerns/EnrichesPlace.php`

## Context (audit finding, 2026-07-21)

`PlaceEditor::apply()` computes `$place->withoutLockedFields($patch)` (PlaceEditor.php:41)
against whatever `locked_fields` are already loaded on the passed-in `$place`, then opens
`DB::transaction(...)` and `$place->save()` (PlaceEditor.php:71-72) — with **no
`lockForUpdate()` and no `refresh()`** inside the transaction.

`BusinessEnricher::enrich()` loads the place, then does multi-second network I/O
(Google/GMB + website scrape + review refresh) **before** calling `editor->apply()` with the
pre-I/O model. Race: enrichment loads Place (locked_fields=[]) → an admin PATCH locks `phone`
and saves → enrichment finishes I/O, diffs against the stale `[]`, and overwrites `phone`.
Because `save()` only writes dirty columns, `locked_fields` in the DB still shows `phone`
locked while the value is enrichment's — **silent corruption**, and it breaks the T-084
"manual override always wins" guarantee.

## Implementation

- Move the read-before-write **inside** `apply()`'s `DB::transaction` closure: `lockForUpdate()`
  and refetch the place, then compute `withoutLockedFields()` / diff against the authoritative,
  just-committed `locked_fields`.
- Keep the change minimal and localized to `PlaceEditor.php`.

## Acceptance criteria

- [ ] `apply()` locks + refetches the place inside its transaction before diffing
- [ ] A manual edit committed during enrichment's I/O window is never overwritten by the
      in-flight enrichment
- [ ] Regression test: enrichment holds a pre-lock Place; a concurrent `apply()` locks `phone`;
      enrichment's later save does NOT overwrite it and `locked_fields`↔value stay consistent
- [ ] Gates: `composer lint` + `stan` + `test` green

## Log

- **2026-07-21** — Filed from the architecture audit. Confirmed against source (no lock/refresh
  in the txn at PlaceEditor.php:41,71).
