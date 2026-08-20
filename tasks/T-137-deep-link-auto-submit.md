# T-137 — Any app can make Reelmap publish a share, with no user tap

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-025 (share-intent receiver + ShareIngest screen)
- **Target paths:** `apps/mobile/app/(main)/share.tsx`,
  `apps/mobile/app/+native-intent.tsx`, `apps/mobile/app/_layout.tsx`,
  `apps/mobile/src/stores/ui.ts`, `apps/mobile/app/(main)/__tests__/`

Mobile review 2026-08-19, findings **MOB-1 (BLOCKING)** and **MOB-8
(IMPORTANT)** — the same twenty lines.

## Context

### MOB-1 — a third-party deep link publishes a share

The share-ingest mount effect (`share.tsx:150-168`) reads `sharedUrl` /
`sharedText` from `useLocalSearchParams` and ends in

```ts
doSubmit(finalUrl, finalCap, 'share_sheet');
```

— a real `POST /shares`, with no tap.

Those params are attacker-reachable. `_layout.tsx:113-116` deliberately lets
`reelmap://` scheme opens fall through to expo-router, and
`+native-intent.tsx:19` returns any non-share-extension path unchanged
(`return path;`). So `reelmap://share?sharedUrl=…` lands directly on that
effect.

**Failure:** any other installed app, or a web page, opens
`reelmap://share?sharedUrl=https://attacker.example/x`. A signed-in user sees
Reelmap come to the foreground and has silently published attacker-chosen
content into their own ingest pipeline, spending one of their capped daily
shares.

**Second defect in the same effect:** only `txt` is regex-checked
(`/^https?:\/\//i.test(txt)`). A value arriving as `u` — the `sharedUrl` param —
is assigned to `finalUrl` with **no** scheme check and forwarded to the API
verbatim, `javascript:` included.

The comment at `:144-149` justifies the param path as a Maestro/CI fallback. It
does not address third-party invocation.

### MOB-8 — sharing the same post twice silently does nothing

The effect dedupes on `handled.current === payload`, and **nothing ever resets
that ref** — not the effect, and not `reset()` ("share another"). The screen is
a `Tabs.Screen` with `href: null`, so it stays mounted, and
`ShareIntentRedirect` only `router.replace`s to it.

**Failure:** share an Instagram post, tap "share another", go back to Instagram
and share **the same post** again. The app foregrounds onto an empty form, the
URL is never prefilled, and no request is made — even though the API supports
the replay and the screen has a `replay` state and an "already added" note that
this makes unreachable.

### Why these are one task

MOB-8's suggested fix — *"key the dedupe on the staged object's identity rather
than the payload string"* — is **mechanically the same refactor** as MOB-1's
*"auto-submit only from the `useUiStore` staged payload"*. Two PRs would
conflict inside one effect, and the second would re-derive the first's
reasoning.

### Same defect shape as T-138

The reviewer named MOB-1 and MOB-11 (→ T-138) as its top pair, for one reason:

> both are places where a validated path and an unvalidated path exist for the
> same input, and the code comments assert they are the same path.

Here the comment calls the param path a CI fallback. There, the notification
centre's header comment claims it is "the identical path the push tap handler
uses". **Fix both by making them one path, not by copying the guard into the
second one.**

## Reproduce it locally FIRST — before writing any fix

**Do not start the implementation until the deep link below has been fired at a
running app and its outcome written into this file.** The chain was traced
through source by the mobile reviewer and independently confirmed against source
by the orchestrator — but it has **not been executed**. This task moves a trust
boundary, and reproducing it is also the only way to prove the fix: with no red
starting point, "no request was made" is indistinguishable from "the deep link
never reached the screen".

### The constraint you cannot route around

The tooling this reproduction needs is **denied by a `PreToolUse` hook**
(`.claude/hooks/guard-simulator-deeplink.sh`). CLAUDE.md rule 6, verbatim:

> 6. **Do NOT use `simctl openurl` to navigate. Navigate with Maestro.**
>    *(enforced — `.claude/hooks/guard-simulator-deeplink.sh` denies it)*
>
>    `simctl openurl` sets the app's *launch URL*, and Expo Router replays it on
>    every reload — so the owner's next **Cmd+R lands on whatever screen you
>    were testing**, not home. This has been reported three separate times
>    ("stuck in offers", "stuck in new offer", "STUCK ON THE WRONG PAGE"), each
>    time caused by an agent's own verification. Remembering to clean up
>    afterwards demonstrably does not work; the fix is to stop creating the
>    state.
>
>    - **Navigate:** `~/.maestro/bin/maestro test` with `launchApp` + `tapOn`. A
>      plain launch carries no URL, so nothing is left behind.
>    - **`openurl` is only acceptable** to reach a screen that genuinely has no
>      in-app path (deep-link handling itself). When you must, finish with
>      `terminate` + plain `launch` — a URL-less launch is what actually clears
>      it (verified: Cmd+R afterwards lands on home).
>    - Other residue to restore: `simctl location set` persists until
>      overwritten (**`clear` is a no-op**) — put it back to Montevideo
>      `-34.9011,-56.1645`; and flying the map persists the viewport.
>    - **Verify the restore, don't assert it.** Send Cmd+R yourself and
>      screenshot where it lands. Twice this was reported "fixed" without that
>      check, and twice it wasn't.

**This task is that carve-out.** The finding *is* deep-link handling: there is
no in-app path that reproduces it, because the whole point is that the payload
arrives from outside the app. A Maestro `launchApp` + `tapOn` cannot express
"another app sent us a URL" — it would test the staged-payload path, which is
the path that is *supposed* to auto-submit.

So the exception applies, and every condition on it is mandatory:

1. Export `REELMAP_ALLOW_DEEPLINK=1` for the call — the hook's own escape hatch,
   and the only sanctioned way past it. Do not reword the command to slip the
   pattern past the matcher.
2. Fire exactly the URLs listed below, and no others.
3. Finish with `terminate` **and a plain, URL-less `launch`**. That is what
   actually clears the launch URL — not `terminate` alone, and not a second
   `openurl` to `/`.
4. **Then send Cmd+R yourself and screenshot where it lands.** Verify the
   restore; do not assert it. Twice this was reported fixed without that check
   and twice it wasn't. If the screenshot is not of home, you are not done.
5. Restore anything else you touched: the simulator location back to Montevideo
   `-34.9011,-56.1645` (`clear` is a no-op), and the map viewport if you flew it.

### Steps

1. Start the app on the simulator with a **signed-in** account, and have the
   API + queue worker running (`./scripts/dev.sh backend`) — without the worker
   nothing publishes and the result is ambiguous.
2. Note the account's remaining daily share quota (`GET /me` → `meta.quotas`)
   **before** anything else. The clearest evidence that a share happened without
   a tap is that this number went down.
3. Background the app, then open `reelmap://share?sharedUrl=https://example.com/x`
   from outside it, under the escape hatch above.
4. Record **verbatim**: did the app foreground onto the Share tab? Was a
   `POST /shares` made with no tap (check the API log / `shares` table)? Did the
   quota decrement?
5. Repeat with `reelmap://share?sharedUrl=javascript:alert(1)` and record
   whether that value reaches the API unmodified.
6. Repeat the FIRST URL a second time without relaunching, and record whether
   anything happens — this is the MOB-8 dedupe, observed rather than reasoned.
7. Perform the restore ritual above, screenshot the post-Cmd+R state, and note
   in this file that you did.

### What to write down here before moving on

- **Did step 4 produce a share the user never asked for?** That is the whole
  finding. If the effect does not fire — if the route params never populate, or
  a guard elsewhere catches it — **the premise is wrong**: stop, record what
  actually happens, and rewrite this task around it.
- Whether the app being **backgrounded vs cold-started** changes the answer. The
  effect is a mount effect on a screen that stays mounted, so those two cases
  can genuinely differ, and the fix has to cover both.
- Whether an **unauthenticated** user hits the same path (this interacts with
  T-142, which finds that the `(main)` group has no auth guard at all).
- The `javascript:` result from step 5, exactly as the API received it.

Record the result whichever way it goes. A finding that does not reproduce is a
valuable outcome and must be written down, not quietly dropped — and the same is
true if it reproduces **worse** than described.

## Implementation

- **Auto-submit only from the `useUiStore` staged payload.** That is the genuine
  share sheet: the payload was staged by `ShareIntentRedirect` from the native
  module, and it is the only source that represents a deliberate user action.
- **A route-param payload prefills and waits for a tap.** The Maestro/CI
  fallback the comment describes still works — it just presses the button, which
  is what a CI flow should have been doing anyway.
- **Validate the scheme on `u`, not only on `txt`.** One check, applied to
  whichever source the value came from.
- **Key the dedupe on the staged object's identity**, or clear
  `handled.current` in `reset()`. Prefer the former: it makes "the same post,
  shared again" a new object and therefore a new submit, which is the behaviour
  the `replay` state was written for.

## Acceptance criteria

- [ ] **REPRODUCE FIRST** — the section above is filled in, including the
      restore screenshot, before any fix is written
- [ ] A route-param payload prefills and requires an explicit tap; only the
      staged payload auto-submits — asserted for both paths
- [ ] The scheme is validated on `u`; a `javascript:` value never reaches the API
- [ ] Sharing the same post twice works, asserted by staging the same payload
      twice with a `reset()` between
- [ ] The `replay` state and the "already added" note are reachable, and a test
      drives them
- [ ] The Maestro/CI fallback still works, now behind the tap

## Gotchas

- **File overlap:** `share.tsx` is also touched by T-131 (the quota guard at
  `:93`). Different region of the file, no dependency — whichever lands second
  rebases.
- Do not "fix" this by removing the route-param path. It is load-bearing for
  Maestro and CI, and deleting it trades a security bug for an untestable
  screen.
- `+native-intent.tsx`'s `return path;` is not itself wrong — it is the correct
  behaviour for a router hook. The trust decision belongs at the screen, where
  the auto-submit is.

---

## REPRODUCTION RESULT — 2026-08-20

Run against `main` @ `8780f67` on branch `feat/T-137-deep-link-auto-submit`,
**no fix written yet**. Simulator: iPhone 16 Pro Max / iOS 18.1, dev client
`pet.one.reelmap`, Metro on `EXPO_PUBLIC_API_URL=http://localhost:8080`,
backend + a fresh queue worker via `./scripts/dev.sh backend`.

### Method deviation (owner-approved)

The escape hatch this task prescribes could not be used as written.
`REELMAP_ALLOW_DEEPLINK=1` is read by the hook from **its own** environment,
which is the Claude Code process — a `export …;` prefix inside the Bash call
never reaches it, because the hook runs before the shell exists. Verified: the
call was denied with the env var exported inline.

The owner chose **Maestro `openLink`** instead (the repo's own
`.maestro/share-to-map.yaml:62` and `share-quota.yaml:45` already enter this
screen that way). Same URLs as listed in Steps, nothing reworded to slip past
the matcher, and the full restore ritual was performed and verified below.

### Baseline

- Account: `user1@example.com` (user id 2), signed in on the simulator.
- `GET /me` → `meta.quotas.shares` = `{used: 0, limit: 100, remaining: 100}`.
- `max(shares.id) = 52`, `max(source_posts.id) = 60`, 0 shares today.
- Incidental: the app was sitting in a stale NetInfo "sin conexión" state and
  made no API calls at all. A plain `terminate` + `launch` cleared it. Not
  caused by this task; worth knowing, because in that state the reproduction
  would have silently produced nothing.

### Step 4 — does a third-party deep link publish a share with no tap? **YES**

App **backgrounded** (Cmd+Shift+H), then
`reelmap://share?sharedUrl=https://example.com/x`:

- The app foregrounded straight onto the Share screen — and not on the form:
  on the **result** state, "Necesita una revisión / No se pudo abrir",
  i.e. the share had already been submitted and had already come back.
- `shares` row **id 62**, `user_id 2`, `shared_via = share_sheet`,
  `source_posts.url = https://example.com/x`, `status = review`.
- Quota `remaining` **100 → 99**.
- Zero taps. The finding reproduces exactly as described.

**Cold start also fires.** `simctl terminate`, then
`reelmap://share?sharedUrl=https://example.com/t137-coldstart` → share **id 67**
created on launch. So the fix has to cover both the mounted-screen case and the
cold-start case; they do not differ here.

### Step 5 — the `javascript:` value: real gap, but the API is a backstop

- `reelmap://share?sharedUrl=javascript:alert(1)` → **no** `shares` row.
- Direct API probe with the same body the client would have sent:
  `POST /api/v1/shares {"url":"javascript:alert(1)","shared_via":"share_sheet"}`
  → **422** `validation_failed` — *"The url field must be a valid URL."*
  So the client did forward it; Laravel's `url` rule refused it.

**The client-side gap is real and was proven with a scheme the API accepts.**
`reelmap://share?sharedUrl=ftp://example.com/t137-scheme` → share **id 66**,
`source_posts.url = ftp://example.com/t137-scheme` — a non-http(s) scheme taken
from an attacker-controlled route param, unchecked by the app, stored verbatim.

API probes, for the record (`StoreShareRequest`'s `url` rule is `filter_var`,
not an http(s) allowlist):

| value | API |
|---|---|
| `javascript:alert(1)` | 422 |
| `javascript://example.com/%0aalert(1)` | 422 |
| `data:text/html,<script>alert(1)</script>` | 422 |
| `ftp://example.com/x` | **202** |
| `file://etc/passwd` | **202** |

So: the task's "a `javascript:` value reaches the API verbatim" is **wrong in
its specific example and right in its substance**. Write the acceptance test
against the client (no non-http(s) value leaves the app), not against the API's
incidental refusal of that one scheme.

### Step 6 — the MOB-8 dedupe: observed at the screen, NOT isolated

- Firing the same URL twice in a row produced no second `shares` row and no
  visible change — but that is **not** proof of the client-side dedupe, because
  the API answers a repeat of the same URL with an idempotent replay. From
  outside, "the ref blocked it" and "the server replayed it" look identical.
- What *is* isolated: a **different** payload always fires.
  `https://example.com/fresh-t137` → share **id 65**, submitted with no tap,
  while the screen was already showing share 62's result. The dedupe is keyed on
  the payload string, so alternating payloads defeats it entirely.
- MOB-8's "share the same post twice and nothing happens" therefore stands as
  **reasoned, not observed** — the simulator cannot raise the OS share sheet,
  which is the only path that produces a *staged* repeat. It must be pinned by
  the unit test the acceptance criteria already ask for (stage, `reset()`,
  stage the same payload again).

### Not executed

- **Unauthenticated.** Testing it means signing the owner's session out of the
  simulator, which was not worth the disruption. Untested here; it is exactly
  the seam T-142 owns (no auth guard on the `(main)` group).

### Restore ritual — performed and verified

`simctl terminate` + plain URL-less `simctl launch`, then **Cmd+R sent to the
Simulator and screenshotted**: the app landed on the **map (home)**, not the
Share screen — no launch URL left behind. Simulator location was never set
(still Montevideo) and the map viewport was never flown.

### Dev-DB artifacts cleaned

`shares` 62–67, `source_posts` 61–66 and the throwaway probe token deleted;
`max(shares.id)` back to 52, `max(source_posts.id)` back to 60, 0 shares today,
quota back to `used: 0`. No `places` or `analysis_runs` rows were produced (all
six shares stopped at `review`).

---

## What the fix ended up covering — 2026-08-20

The reported finding (a route param auto-submitting) is closed, and the review
that followed turned up **three more instances of the same shape** that the task
did not name. All are in the diff:

1. **Android's staged path is forgeable, so it no longer auto-submits.**
   `app.config.ts` registers `androidIntentFilters: ['text/*']` on the exported
   MainActivity, so any installed app can `startActivity` an EXPLICIT
   `ACTION_SEND` with `setPackage(us)` — no chooser, no tap — and land in the
   same `useUiStore.pendingShare` the iOS extension writes. iOS's payload comes
   from the app group, which no other app can write; Android's does not. So the
   auto-submit is gated to iOS and Android prefills and waits for the button.
   **Traced through expo-share-intent's source by two independent reviewers;
   NOT reproduced on an Android device** — there is no Android device on this
   machine. Worth confirming on the physical device before launch; if it turns
   out the intent cannot be sent explicitly, the gate is one line to relax.

2. **A repeated param crashed the screen.**
   `?sharedUrl=a&sharedUrl=b` makes `useLocalSearchParams` hand back an array,
   and `.trim()` on an array is a TypeError that takes the screen down —
   reproduced (`(rawUrl ?? '').trim is not a function`) and now read through
   `firstParam`. Covered by a unit test and by PART 4 of the Maestro flow.

3. **A dropped payload could still WIPE the composer.**
   The first cut of the prefill called `setUrl('')`/`setCaption('')`
   unconditionally, so `?sharedUrl=ftp://evil` could not submit but could still
   erase an in-progress composition — and a *different* payload could swap out a
   link the person had typed and was about to submit. The prefill now fills only
   an untouched form and never clears one.

**The scheme check moved to the request site, and the server got its own.**
`splitPayload` guards the two prefill paths, but the composer field is free text
and reached `create.mutate` unchecked, so the gate now lives in `doSubmit` — the
one place a request is made. And the API's `url` rule is `filter_var`, which
accepts ~400 schemes: `ftp://example.com/x` and `file://localhost/etc/passwd`
both returned **202** against the running dev API. `StoreShareRequest` now uses
`url:http,https`, with Pest cases proving it both ways (five schemes refused,
three accepted) — a shipped mobile build cannot be revised, so the client's
guard must not be the only one.

### Known limitation, deliberately left

Re-opening the **same** deep link after "share another" prefills nothing: the
route params never change, so React cannot re-run the effect, and clearing the
ref does not help. Fixing it means consuming the params (`router.setParams`),
which this task does not need — only external links and the CI flows use that
path. The share-sheet path, which people actually use, handles a repeat
correctly; that is the MOB-8 half of this task.

### Follow-ups worth their own tasks (NOT folded in)

- **`splitPayload` and its twins should be one helper.** `src/api/shares.ts`'s
  `extractUrl` (loose, pulls a link out of text) and
  `src/components/map/quick-share.tsx`'s inline `/^https?:\/\//i` make the same
  url-vs-caption decision three ways, and they already disagree: the same text
  shared via the iOS sheet becomes a `url`, via a deep link a `caption`. T-131
  already owns the quick-share duplication — reconcile there.
- **The caption is discarded whenever a URL is present** (`caption: url ? '' : txt`,
  preserved from the old code). The Instagram extension stages both, so the
  post's caption never reaches extraction on the app's primary entry point.
- **`expo-share-intent` force-unwraps `url.fragment`** in its iOS module, so
  `reelmap://dataUrl=x` (no fragment) hard-crashes the app. Library bug, not
  ours, but externally reachable.
