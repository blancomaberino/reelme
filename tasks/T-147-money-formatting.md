# T-147 — `formatMoney` has no grouping separator and renders UYU as `$`

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-046 (wallet API + mobile screens), T-048 (influencer
  dashboard metrics)
- **Target paths:** `apps/mobile/src/api/wallet.ts`,
  `apps/mobile/src/lib/use-format.ts`, `apps/mobile/app/(main)/wallet.tsx`,
  `apps/mobile/app/influencer/dashboard.tsx`,
  `apps/mobile/src/lib/__tests__/`

Mobile review 2026-08-19, finding **MOB-12**.

## Context

`wallet.ts:81-85`:

```ts
const symbol = money.currency === 'EUR' ? '€' : money.currency === 'GBP' ? '£' : '$';
return `${symbol}${(money.amount / 100).toFixed(2)}`;
```

No grouping separator, `.` as the decimal mark regardless of locale, and **UYU
falls into the same `$` branch as USD**.

**Failure:** an influencer's balance renders as `$1234567.89` — a Spanish reader
parses `.89` as a thousands group, i.e. reads the number wrong by two orders of
magnitude, **on a money screen** — and a UYU balance is visually identical to a
USD one.

`apps/api/config/monetization.php:33` defaults to EUR and is env-driven, so the
currency really does vary. This is not hypothetical.

Used at `wallet.tsx:129,138,192,273`, `influencer/dashboard.tsx:173,206,238`,
and in push copy.

## Implementation

The right home already exists and already documents the hard part.
`src/lib/use-format.ts` is the locale-aware display layer, bound to the settings
store, and its header records why it hand-rolls month names:

> Hermes ships only a partial Intl and `toLocaleDateString` options aren't
> reliable.

**Money has the same constraint.** Read that comment before reaching for
`Intl.NumberFormat` — and if it turns out to be usable for currency on the
current Hermes, say so *there*, rather than leaving two contradictory
assumptions in one file.

**A per-currency table beats a wider ternary.** A ternary with four branches is
the same bug one currency later, and the API can emit whatever
`monetization.php` is set to.

## Acceptance criteria

- [ ] A balance of `123456789` minor units renders with a grouping separator and
      the locale's decimal mark in both dictionaries — asserted for `es` and
      `en`, since `1.234.567,89` vs `1,234,567.89` is the whole point
- [ ] UYU is visually distinguishable from USD; every currency
      `config/monetization.php` can emit has a defined rendering, asserted per
      currency rather than by a ternary
- [ ] The formatter lives alongside `use-format.ts` and honours its documented
      Hermes constraint
- [ ] Every call site and the push copy go through the one formatter

## Gotchas

- Money arrives in **minor units**. Any refactor that starts formatting a float
  has introduced a worse bug than the one it fixed.
- `formatMoney` is currently a pure function outside the store; `use-format.ts`
  is a hook bound to settings. Moving it changes how call sites consume it —
  check the push copy path, which may not be inside a component.
- The wallet docblock explains that the client never sends an amount ("the
  payout is always all of it, so there is no field to mis-parse"). That remains
  true and is a reason this is display-only — do not let the fix grow into
  parsing.
