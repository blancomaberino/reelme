# T-138 — The in-app notification centre skips the URL guard the push handler uses

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-040 (notification center: API + mobile), T-027 (devices +
  push notifications)
- **Target paths:** `apps/mobile/app/notifications.tsx`,
  `apps/mobile/src/notifications/routing.ts`,
  `apps/mobile/src/notifications/__tests__/`

Mobile review 2026-08-19, finding **MOB-11** — one of the reviewer's top two.

## Context

`notifications.tsx:121`:

```ts
if (item.url) router.push(item.url as never)
```

The push-tap path routes the **identical** server-supplied `data.url` through
`urlFromData()` (`routing.ts:24-28`), whose own comment calls it *"defense in
depth on router.push"* — leading `/` required, protocol-relative `//host`
rejected. The in-app centre reads the same field off `GET /notifications` and
skips the guard entirely.

Two details make this more than a missing call:

- **The header comment at `:26` claims** it is "the identical path the push tap
  handler uses". It isn't — and the comment is why nobody looked.
- **The `as never` cast is what silenced the type error** that would otherwise
  have caught it. A cast that exists to make a call compile is a cast that has
  removed the only check on that call.

### Same defect shape as T-137

The reviewer named MOB-1 (→ T-137) and MOB-11 as its top pair for one reason:

> both are places where a validated path and an unvalidated path exist for the
> same input, and the code comments assert they are the same path.

## Implementation

The one-line version is
`const url = urlFromData({ url: item.url }); if (url) pushUrl(url);` — and it
fixes today's bug while leaving **two** navigation paths that a future change
can separate again.

Make it **one helper both callers use**, and **delete the cast** rather than
re-typing around it. That is what makes the header comment true instead of
aspirational.

## Acceptance criteria

- [ ] A server-supplied `url` of `//attacker.example`, or one without a leading
      `/`, does **not** navigate from the in-app centre — asserted with the same
      fixtures the push-tap path is tested with
- [ ] There is ONE guarded navigation helper; both callers use it
- [ ] The `as never` cast is gone, and the type is the one the guard returns
- [ ] A legitimate notification still navigates, for every url shape the API
      actually emits

## Gotchas

- Enumerate the url shapes the API emits before tightening the guard — a rule
  that rejects a real notification type turns a security fix into a dead inbox.
- The guard belongs on the client **and** the server should not be emitting
  attacker-controlled urls in the first place. This task is the client half;
  if the review of `data.url`'s provenance turns up anything, file it rather
  than widening this.
