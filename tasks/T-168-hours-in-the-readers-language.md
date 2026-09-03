# T-168 — Opening hours reach a Spanish reader in Spanish

- **Phase:** GROW · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-155 (the structured periods + timezone this generates from)
- **Target paths:** see tasks.json

## Why

The app is voseo Spanish. The expanded hours block reads:

```
Monday: Closed
Tuesday: 12:00 - 4:00 PM, 8:00 PM - 12:00 AM
```

English day names, English "Closed", 12-hour clock with AM/PM — in an app whose
every other string is Spanish. Reported by the owner from a screenshot.

## Two causes, and the second is the real one

**1. We never ask Google for a language.** `GooglePlacesGeocoder::businessDetails()`
is the only Places call in that file that omits `language`; the geocode calls
both pass `$hints->language`. So Google answers in English and the English is
what gets stored.

Necessary, but not sufficient — and it carries a trap. That response is cached
for 30 days under `geocode:business:sha1(place_id)`, with **no language in the
key**. Adding a per-request language without changing the key would serve
whichever locale asked first to everyone else.

**2. `opening_hours_json` is one column.** Whatever language we ask Google for,
server-stored prose can only ever be ONE language for all readers. A per-reader
answer cannot come from that column at all.

## The fix

**Generate the lines** from `opening_hours_periods_json` + `timezone` in the
request's locale. Fall back to the stored prose only for places that have no
structured hours.

This is exactly what T-155 unlocked, and it lifts the constraint `hourLines`
documents at length: from prose, the day ORDER is unknowable (`weekday_text` is
ordered by the source locale's first day of week, so no index is a fixed
weekday) and "which line is today" is a guess. From `open_day` both are facts.

**Do not reintroduce text parsing to get there.** Deriving Spanish from English
prose is the T-128 defect wearing a hat, and the periods exist precisely so
nobody has to.

## Acceptance

See tasks.json. The load-bearing pair: a place WITH periods renders different
day names for `Accept-Language: es` and `en`; a place WITHOUT them still renders
its stored prose verbatim and untranslated.
