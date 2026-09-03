# T-162 — Been-there: the tap that completes a want-to-go pin

- **Phase:** GROW · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** nothing
- **Target paths:**
  `apps/api/database/migrations/`,
  `apps/api/app/Http/Controllers/Api/V1/PlaceController.php`,
  `apps/api/app/Models/Builders/PlaceQueryBuilder.php`,
  `apps/mobile/app/place/[slug].tsx`,
  `apps/mobile/src/components/map/filter-bar.tsx`

## Why

FR-28 promises want-to-go / been / favorite with a private note. Only lists and
private tags shipped. So a user saves a place, actually goes, comes home -- and
there is nothing in the app to press.

Beyond the missing satisfaction, this is the signal Tonight needs to rank with:
"you have been three times" and "you saved this four months ago and never went"
are different recommendations. The owner's model makes want-to-go the primary pin
state, which makes been-there its completion rather than a separate feature.

## Acceptance

- want-to-go / been / favorite persist per user per place and are filterable on the map
- The states are private: another viewer's profile or public map never exposes them, asserted
- Toggling a state re-queries the map rather than mutating a stale client set
- A place can be marked been without having been want-to-go first
- The controls carry accessible labels and announce their state to VoiceOver

## Notes

Filed 2026-09-03. FR-28 was specified in M2 and never built. Kept small on purpose: the private note is in scope, social visibility is explicitly not.
