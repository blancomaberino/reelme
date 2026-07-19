# T-083 — "Suggest an edit" flow for place info + business-owner management

- **Phase:** M4 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-030 (places API), T-041 (place claims + `is_restaurant_owner`)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/PlaceEditSuggestionController.php`, `apps/api/database/migrations/`, `apps/api/app/Models/`, `apps/api/app/Filament/Resources/`, `apps/mobile/app/place/[slug].tsx`, `apps/mobile/app/restaurant/`
- **Spec refs:** `06-monetization.md#restaurant-program`, `02-data-model.md`, `03-api-design.md#places`

## Context

Requested 2026-07-17. A **"suggest changes"** button so place info can be
corrected — crowd-sourced from any user, and eventually managed by the venue's
owner. The request explicitly wants: (a) a way to submit suggested edits, (b)
eventually a `business_owner`-type user who can manage them, and (c) **no change**
to how a place surfaces once shared — it still appears in the feed as a **pin plus
the source post it was seen in**, exactly as today.

### Grounding — reuse T-041, don't add a new role

- Roles are **boolean flags** on `users` (`is_influencer`, `is_restaurant_owner`,
  `is_admin`) — not an enum. **`business_owner` == the existing
  `is_restaurant_owner`.** Do NOT add a new role/column.
- **T-041 (Place claims)** is the verified-ownership mechanism: a verified
  `place_claims` row grants `is_restaurant_owner` and (per its spec) a
  place-scoped owner role, with `User::ownsPlace(Place)` as the canonical check
  and a Filament approval queue. This task **builds on** that — owner management is
  the T-041 verified-owner path; the crowd-sourced suggestion box is open to all.
- **Place fields** live on the `places` table (name, address_*, `opening_hours_json`,
  phone, website, `google_place_id`) plus read-time aggregates (dishes/tags, and
  card discounts once T-079 lands). Approved edits patch the `places` columns.
- **Feed/pin behavior is already correct** (T-032 map pins, T-034 feed, source
  attribution) — this task must not regress it. No change to ingestion/attribution.

## Implementation

- **Migration** `place_edit_suggestions`: `place_id` FK→places (cascade),
  `user_id` FK→users (nullable/SET NULL), `changes_json` (proposed field patch),
  `status` varchar CHECK `pending|approved|rejected` default `pending`,
  `is_owner_submission` bool, `reviewed_by_user_id` FK→users (SET NULL),
  `reviewed_at`, `reason` (for rejects). Index (`place_id`,`status`).
- **API:** `POST /api/v1/places/{place}/suggestions` (auth: any user) — validated
  field patch (whitelist: name, address, hours, phone, website, discounts). Owner
  submissions (`ownsPlace`) may apply directly or fast-track; non-owners always
  queue.
- **Apply service:** approving a suggestion patches the whitelisted `places`
  fields inside a transaction with an audit trail (who/when/what); rejecting
  records a reason. One code path for owner-direct and moderator-approve.
- **Filament** `PlaceEditSuggestionResource`: moderation queue (place, submitter,
  diff of `changes_json`, owner badge) with Approve/Reject.
- **Mobile:** a "Sugerir un cambio / Suggest an edit" entry on place detail opens
  a form; verified owners get a management surface under `restaurant/` (reuses the
  T-041/T-042 "My restaurant" entry point) to edit directly + see pending items.
- **Preserve sharing:** assert (test) that a place with suggestions/owner edits
  still surfaces in the feed as a pin + its source post — no attribution change.

## Acceptance criteria

- [ ] `place_edit_suggestions` table + model; "suggest an edit" open to any authed
      user via `POST /places/{id}/suggestions` (whitelisted fields, validated)
- [ ] Filament moderation queue: approve patches the target `places` fields with an
      audit trail; reject records a reason
- [ ] Verified owners (`is_restaurant_owner` via a T-041 verified `place_claim`) get
      a management surface where edits apply directly / fast-track; non-owners queue
- [ ] Sharing preserved: a suggested/owner-managed place still surfaces as a pin +
      the source post (regression-tested); no ingestion/attribution change
- [ ] Reuses `is_restaurant_owner` (place-scoped via T-041) — **no** new
      `business_owner` role/column
- [ ] Tests: any user can suggest; owner edit applies; non-owner queues; approve
      patches place + audit; authz denial (non-owner can't self-approve); Pint/
      PHPStan L6/Pest + mobile jest/tsc green

## Verification

```bash
cd apps/api
php artisan test --filter='PlaceEditSuggestion|Suggestion'
vendor/bin/pint --test && vendor/bin/phpstan analyse
cd ../mobile && npx tsc --noEmit && npx jest place
```

Manual: as a normal user submit an edit → lands in the Filament queue; approve →
place fields update. As a verified owner (seed a T-041 claim) edit directly →
applies without queueing; confirm the place still shows in the feed with its pin
and source post.

## Gotchas

- **Sequencing:** T-083 depends on T-041 for verified ownership. The crowd-sourced
  suggestion box + moderation can ship first; the owner-direct path activates once
  T-041's verified claims exist — scope so the moderated path stands alone.
- **Field whitelist only** — never let a suggestion patch arbitrary columns (status,
  `google_place_id`, counters, `merged_into_place_id`). Validate against an
  explicit allow-list.
- Keep the owner-direct apply and moderator-approve on **one** `applyChanges`
  service so the audit trail and field-whitelist are identical for both.
