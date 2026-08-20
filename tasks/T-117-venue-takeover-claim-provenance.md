# T-117 — Venue takeover: claim verification trusts a website the claimant typed

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-041 (place claims: methods, evidence, verification, Filament queue)
- **Target paths:** `apps/api/app/Services/Places/PlaceFactory.php`,
  `apps/api/app/Services/Places/PlaceClaimService.php`,
  `apps/api/app/Services/Geo/GeocodeResult.php`,
  `apps/api/app/Services/Geo/GooglePlacesGeocoder.php`,
  `apps/api/app/Enums/PlaceClaimMethod.php`, `apps/api/database/migrations/`

Security review 2026-08-19, finding **SEC-1 (HIGH, CWE-807)**. The launch
blocker of the wave.

## Context

`PlaceClaimService` writes down both the invariant and the attack, in its own
docblock:

> every method proves control of something the PLACE record already lists,
> never something the claimant typed... A claimant who could nominate the
> domain could verify any venue on the map, which is the whole attack.

The invariant does not hold. `PlaceFactory::create()` builds a new pin from two
sources and does not distinguish them: name, address, coordinates and
`google_place_id` come from Google (`$geo`), while

```php
'phone'   => $this->truncate($place['phone'] ?? null, 32),
'website' => $this->truncate($place['website'] ?? null, 2048),
```

come from **the LLM extraction** — which the sharer can rewrite.

### The exploit, verified end to end by the reviewer

1. register
2. `POST /api/v1/shares` for an unmapped real restaurant
3. `PATCH /api/v1/shares/{id}` with
   `{"extraction":{"places":[{"website":"https://attacker.tld"}]},"action":"publish"}`
   — `UpdateShareRequest.php:25` accepts `extraction` as a free-form array, and
   the only gate is the JSON Schema, where `website` is
   `{"type":["string","null"],"format":"uri"}`: no host constraint, no scheme
   constraint. The pin is created with the **real venue's Google identity** and
   the **attacker's domain**.
4. `POST /places/{place}/claim {method: website}`
5. publish the token at `https://attacker.tld/.well-known/reelmap-verify.txt`
6. `POST /places/{place}/claim/verify` → **verified operator**

No LLM cooperation is needed at any step — the correction path is enough.

### What the attacker gets, and what the owner loses

`is_restaurant_owner` on a real restaurant's canonical pin: un-reviewed edits to
its business info, offer creation, the venue's redemption log, and the
redemption `verify` capability that mints ledger entries.

And `assertClaimable()` checks only for a pre-existing **verified** claim — no
place-status gate, first-come-first-served — so the genuine owner who arrives
later gets `already_claimed` and is permanently locked out.

## Reproduce it locally FIRST — before writing any fix

**Do not start the implementation until the chain below has been run against a
running local stack and its outcome written into this file.** The exploit was
traced through source by a reviewing agent; it has **not** been executed against
a running Reelmap. Every line number and every claim above is a reading of the
code, not an observation of the app. This task changes an authorization
boundary and adds a migration — building that on an unconfirmed premise is how
the wrong thing gets hardened.

Reproducing it is also the only way to know the fix worked: without a red
starting point, "the claim is refused" is indistinguishable from "the claim
never got that far for some unrelated reason."

### Steps

1. `./scripts/dev.sh backend` (API on `:8080`, queue worker up — the share
   pipeline needs the worker or nothing publishes).
2. Register a throwaway user and keep the token.
3. `POST /api/v1/shares` for a real restaurant that is **not** already mapped.
   Let the extraction run to `review`.
4. `PATCH /api/v1/shares/{id}` with an attacker-chosen website:
   `{"extraction":{"places":[{"website":"https://attacker.example"}]},"action":"publish"}`
5. Read the created row — `docker compose exec -T laravel.test php artisan tinker`
   — and record **verbatim** what `places.website`, `places.google_place_id` and
   `places.name` actually contain.
6. `POST /api/v1/places/{place}/claim` with `{"method":"website"}` and record
   whether it is accepted and what token it issues.

### What to write down here before moving on

- **Does step 5 show the attacker's domain sitting next to the real venue's
  Google identity?** That is the whole finding. If the extraction value is
  overwritten, ignored, or the pin is not linked to the real venue, **the
  premise is wrong** — stop, and rewrite this task around what actually happens.
- Which place `status` the pin lands in, and whether `assertClaimable()` lets a
  non-`active` pin be claimed at all. The status gate in the acceptance criteria
  assumes it currently does; confirm that rather than inheriting it.
- Whether step 6 can be completed without owning `attacker.example` — i.e. how
  far a real attacker gets before needing to host a token. If the claim is
  refused earlier than the review supposed, the fix may be smaller than this
  task assumes.

Record the result in this section, whichever way it goes. A finding that does
not reproduce is a valuable outcome and must be written down, not quietly
dropped — the same is true if it reproduces **worse** than described.

## REPRODUCTION RESULT (run 2026-08-19, local stack, worker up)

**The finding reproduces — confirmed end to end through claim issuance.** Ran
against a real venue ("Jacinto", Sarandí 349, Montevideo — not previously mapped).

**Chain as executed:**
1. Registered throwaway user; register response returns a working bearer token
   (no email-verify gate on the API routes). ✓
2. `POST /shares` with a `caption` (manual path, no Instagram fetch / no yt-dlp).
   Local Ollama (`gemma4:latest`) extracted it → share parked at `review`
   (confidence 0.6, extracted `website: null`). ✓
3. `PATCH /shares/{id}` with
   `{"extraction":{...,"places":[{"name":"Jacinto","phone":"+598 99 111 222","website":"https://attacker.example",...}]},"action":"publish"}`
   — accepted; the JSON Schema does not constrain host/scheme. Share → `analyzing`. ✓
4. Worker ran `resolve → publish`. Google geocoded "Jacinto" to the **real
   venue's identity** and the pin published as **`active`**.

**The created row (`places.id = 30`), read verbatim via tinker:**
```
name            = Jacinto
status          = active          <-- NOT pending; see status-gate note below
google_place_id = ChIJnaZ1dYF_n5UR5cqVJrdd01U   (the REAL Jacinto — rating 4.3, 2331 reviews)
website         = https://attacker.example      <-- THE ATTACKER'S DOMAIN. This is the finding.
phone           = +598 2915 2731                <-- Google's real number, NOT the +598 99 111 222 I typed
google_rating   = 4.3   count = 2331
enriched_at     = 2026-08-19 21:53:51
locked_fields   = []
```
So: **the attacker's domain sits next to the real venue's Google identity.**
The premise holds exactly as the review described — for `website`.

5. `POST /places/30/claim {method: website}` → **accepted**, HTTP 201:
   ```
   status: pending, method: website, token: reelmap-verify-gl9wntvbnarpjyotv57szkpz,
   verification_url: https://attacker.example/.well-known/reelmap-verify.txt
   ```
   The token is issued for a host the claimant nominated. Step 6 (host the file,
   `POST /claim/verify`) needs only a file on `attacker.example`, which the
   attacker owns by definition. Not executed here — the `PublicUrlGuard`
   correctly blocks pointing the verifier at a private host, so there was no way
   to fake ownership of a public domain locally. The claim being *accepted with a
   token for the attacker's own host* is the confirmed exploitable state.

### Two facts the review did NOT have — they change the fix, not the verdict

**(a) `phone` is scrubbed by auto-enrich; `website` is not — because Google had
no website for this venue.** `PublishShare` auto-enriches every new pin
(`EnrichPlace`, `config('places.enrich.auto')` default true → `BusinessEnricher`
→ Google `BUSINESS_FIELDS`). `BusinessDetails::toPlacePatch()` `array_filter`s
out empty values, so enrichment overwrites a field **only when Google has one**.
Google returned a phone for Jacinto (my typed `+598 99 111 222` → real
`+598 2915 2731`, so the phone-claim OTP would go to a number the attacker does
not control) but **no** website, so `attacker.example` survived untouched and
became claimable. For a venue where Google also has no phone, the typed phone
survives too and the phone vector is live as well.

This is exactly why **provenance, not the current value, is the axis**: after
enrich this row's phone has Google provenance (safe to claim by phone) while its
website has extraction provenance (must be refused). A fix that stamps
provenance at each write point — `extraction`/`share-correction` in
PlaceFactory & the correction path, `business-enrichment` when the Google enrich
patch writes phone/website — and gates the automatic claim methods on
provenance ∈ {google, business-enrichment}, closes this precisely.

**(b) The place is `active`, so the proposed status gate does NOT close SEC-1.**
`action: publish` sets `user_confirmed = true`, and `PlacePublisher` promotes a
`user_confirmed` pin straight to `active`. So `assertClaimable` refusing a
non-`active` place would not have blocked this chain. The status gate is still
worth adding as defense-in-depth (a `pending` pin nobody reviewed should not be
claimable), but it must be described as such — the **website-provenance check is
the fix that actually closes the launch blocker**.

### Backfill confirmed necessary
6 existing dev pins already carry websites; all were written by `origin=enrichment`
(Google) — but the row records no provenance, so post-migration they are
indistinguishable from an extraction-sourced one unless "unknown ⇒ untrusted" is
the backfill default. Confirmed: `place_edits` for these rows are `origin=enrichment`,
but nothing on `places` itself records that today.

## Implementation

### The reviewer's suggested fix is not available as written

The recommendation was "accept only a provider-sourced contact field (the Google
Places website/phone already on GeocodeResult)". **`GeocodeResult` carries no
website and no phone.** Confirmed in source:

- `GeocodeResult` has `googlePlaceId, canonicalName, formattedAddress,
  addressComponents, lat, lng, types, score, rating, ratingCount, reviews` —
  and nothing else.
- `GooglePlacesGeocoder::DETAILS_FIELDS` (the pipeline mask) is
  `place_id,name,formatted_address,address_component,geometry/location,type,rating,user_ratings_total,reviews`,
  with a comment: *"Minimal Place Details field list — widening this raises the
  billed SKU."*
- Google's website and phone arrive **only** through `BUSINESS_FIELDS`
  (`international_phone_number,formatted_phone_number,website,opening_hours,...`),
  used by the **admin-triggered enrich path** (T-084) and mapped into
  `BusinessDetails`, explicitly *"the extra contact/hours fields the pipeline
  never needs, so this SKU is paid only when an admin explicitly enriches"*.

So this is not a one-line swap, and **widening `DETAILS_FIELDS` has a per-call
billing cost the code comments about deliberately.** Decide consciously; do not
widen it silently to make the fix easy.

### The shape of the fix

- **Record provenance for `website` and `phone`** — where each value came from
  (google / business-enrichment / extraction / share-correction / admin), on the
  row, at write time. Provenance decided at write time is checkable; provenance
  inferred at claim time is a guess.
- **The automatic methods consume provenance, not the value.**
  `PlaceClaimMethod::Website` and `::Phone` become available only when the
  field's provenance is a provider. Everything else routes to `Document`, which
  already exists for exactly this: *"The fallback for a place with neither a
  phone nor a website on file, which is common for the long tail."*
- **Gate on place status too.** `assertClaimable()` should refuse a place that
  is not `active`. A pending pin nobody has reviewed is currently claimable, and
  that is half the exploit's speed.
- **Consider `locked_fields`.** `LocksFields` / `CURATED_FIELDS` is already the
  repo's vocabulary for "this field is not the pipeline's to touch". Two
  parallel mechanisms for *who owns this field* will diverge — decide whether
  provenance belongs alongside it, and say why in the PR either way.

### Backfill

Existing pins already carry extraction-sourced websites. Provenance for rows
written before this change is unknown, and "unknown" must be treated as
untrusted — otherwise the migration hands the attack to everything already in
the database.

## Acceptance criteria

- [x] **The exploit chain was reproduced locally before any code was
      written**, and its actual outcome is recorded in the "Reproduce it
      locally FIRST" section above — including the case where it does not
      reproduce as described
- [x] A place whose `website`/`phone` came from an extraction or a share
      correction **cannot** be claimed by that method — proven end to end:
      publish an attacker-chosen website through `PATCH /shares/{id}`, then
      assert the claim is refused
- [x] Automatic methods accept only provider-sourced values; provenance is
      recorded on the row, not inferred at claim time
- [x] `assertClaimable()` refuses a claim on a place that is not `active`
- [x] A place with no provider-sourced contact field is routed to `document`
      and told so — nobody is dead-ended, and that path is asserted
- [x] Existing rows are backfilled as untrusted
- [x] The docblock invariant is asserted by a test that FAILS if the extraction
      value is trusted again

## Gotchas

- **Do not fold SEC-2 or SEC-4 into this PR.** They ride the same
  `PATCH /shares/{id}` seam and this fix closes neither: an attacker who cannot
  claim can still poison the publish gate (T-118) and still write content onto a
  stranger's place (T-119). Three fixes, three subsystems. Bundling parks the
  launch blocker behind two open design questions.
- The website verification itself is **good** — `PlaceClaimService::verifyWebsite`
  already calls `PublicUrlGuard::assertPublic`, refuses redirects with a comment
  explaining why, and does not burn the claim on a transient fetch failure.
  Nothing there needs changing. The bug is entirely in *which* website it trusts.
- `already_claimed` deliberately does not reveal who holds the claim. Keep that
  when adding the status gate — a new error message must not become an oracle.
