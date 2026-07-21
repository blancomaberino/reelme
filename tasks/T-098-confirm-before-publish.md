# T-098 — Confirm-before-publish for uncertain shares + admin-owned cleanup

- **Phase:** M3 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-026 (review/status screens), T-072 (admin moderation)
- **Origin:** Owner reframe on 2026-07-21 while reviewing T-026 on device.

## The insight

Correcting the AI's extraction is **not a user's job**. A sharer wants to save a place and keep track of it — not babysit a `review` queue. T-026 (correctly, for its scope) made `review` a place the *sharer* fixes; the owner wants to flip that: the correction UI becomes a **pre-publish confirm** only when we're uncertain, and any uncertainty nobody confirms is an **admin/moderation** concern, not the user's.

## The model (owner-decided)

- **Confident** → auto-publish silently (unchanged).
- **Uncertain + first sharer** (low overall confidence OR ambiguous dedupe match) → publish the **best guess immediately** (so the place always appears and is tracked) *and* surface the T-026 review form inline as a "look right? tweak it" step.
  - Confirm / tweak → the pin updates with clean data.
  - Skip / back out → stays live on best-guess **+ flagged for the admin moderation queue**.
- **Re-share of an existing place** → attach, no confirm.

Decisions captured via AskUserQuestion (2026-07-21):
1. **Skip behavior:** *Publish + flag for admin* (place always appears; admin cleans up later).
2. **Confirm trigger:** *Only when uncertain* (confident auto-publishes silently).

## The one wrinkle

`geocode_failed` has **no location**, so it can't drop a public pin. Exception: it's still tracked for the sharer and routed to the admin queue **to be located** (via the confirm pin, or by an admin); it doesn't hit the public map until it has a spot. Everything else (ambiguous match, low-confidence-but-geocoded) publishes best-guess immediately.

## Layers

- **Mobile:** reframe the share flow so an uncertain first-share opens the T-026 form inline as "confirm before it's live" (copy: *we found this — publish or tweak*, not *needs review*). The screens are reused wholesale.
- **Backend:** uncertain first-shares publish best-guess + set a `needs_admin_review` flag instead of dead-ending on the user; abandoned confirms don't strand (already published + flagged). Handle the `geocode_failed`→hold-from-public exception.
- **Admin (Filament):** a "needs review" queue/filter so an admin — not the sharer — cleans up flagged extractions (dovetails with T-072 moderation batch + T-049 reports).

## Also fold in

The `reelmap://` deep-link guard: `ShareIntentRedirect` (T-025) treats incoming app-scheme URLs as share intents, so a push-notification deep link into `reelmap://shares/[id]/status` (T-027) or a `reelmap://list/...` link can bounce the user to the composer. Guard it to ignore intents whose URL is an internal app route. (Could also land with T-027.)

## Acceptance

See tasks.json T-098 acceptance.
