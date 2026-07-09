# packages/contracts

Shared JSON Schemas + generated TypeScript types — built in **T-005**.

These schemas are the **single source of truth** for API/mobile data shapes (shares, places, analysis runs, offers, redemptions, ledger entries, errors). The Laravel API contract-tests its JSON resources against them; the mobile app generates its TS types from them via `npm run generate`.
