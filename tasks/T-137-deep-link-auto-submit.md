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
