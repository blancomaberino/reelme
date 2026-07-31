# T-111 — Mobile: resolve the Expo SDK 57 package skew (suspected native crash source)

- **Phase:** ARCH · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** —
- **Target paths:** `apps/mobile/package.json`, `apps/mobile/ios/`, `package-lock.json`

## Context

Flagged but deliberately not bundled in T-100 (commit `1cad142`). `npx expo install --check`
reports 17 outdated packages against what the installed SDK's own
`bundledNativeModules.json` asks for:

```
expo 57.0.4 → ~57.0.9          react-native 0.86.0 → 0.86.2
expo-secure-store 57.0.0 → ~57.0.1   react-native-reanimated 4.5.0 → 4.5.1
expo-splash-screen 57.0.2 → ~57.0.5  react-native-screens 4.25.2 → ~4.26.0
expo-status-bar 57.0.0 → ~57.0.1     react-native-worklets 0.10.0 → 0.10.1
expo-system-ui 57.0.0 → ~57.0.2      jest-expo 57.0.1 → ~57.0.3   (+ others)
```

T-100 already lost time to one ABI mismatch in this family: `expo-location` resolved to
57.0.7 while the project pins `expo-modules-core` 57.0.3, and the app aborted in dyld
before any JS ran. It was fixed by exact-pinning that one package — the underlying skew
was left in place.

## The crash that motivates the priority (2026-07-30)

The app took a **native** crash on the simulator:

```
EXC_BAD_ACCESS (SIGBUS) · EXC_ARM_DA_ALIGN — possible pointer authentication failure
  hermesvm          facebook::jsi::Value::~Value()
  ExpoModulesJSI    JavaScriptValuesBuffer.deinit
  ExpoModulesCore   ConcurrentFunctionDefinition.call(_:this:arguments:)
  ExpoModulesJSI    JavaScriptRuntime.createAsyncFunction / .schedule(priority:)
```

The faulting frame is inside **Expo's async native-function bridge**, not app JS —
teardown of the JS value buffer for a concurrent (async) module call.

Why that points here: `expo-secure-store` is one of the skewed packages **and** is the
async module that runs at boot. `app/_layout.tsx` hydrates the session token, the
settings store (two reads) and — since T-100 — the map viewport, so a cold launch fires
several concurrent async Expo calls at once. That is exactly the shape of call in the
stack.

**Not yet proven.** Seen once; did not reproduce over six cold relaunches after a Metro
cache clear. Treat it as intermittent: a single clean launch is not evidence of a fix.

## Acceptance criteria

- [x] `npx expo install --check` reports no outdated packages for the mobile app
- [x] The iOS dev client is rebuilt against the aligned versions; `Podfile.lock` committed
- [x] jest / tsc / lint stay green after the bump; breaking changes are handled, not pinned around
- [x] The app survives **10 consecutive cold launches** with no new entry in
      `~/Library/Logs/DiagnosticReports` (see the harness below)
- [x] If the crash still reproduces after alignment, it is written up — minimal repro plus
      an upstream issue link — rather than closed silently

## What is actually established (2026-07-30 investigation)

- The crash is **real and recurring**: two occurrences, 20:45 and 20:55, with an
  **identical** faulting stack.
- **Reload is a far better trigger than cold launch.** 12 cold relaunches produced
  nothing; driving `reload` over the Metro dev-server websocket killed the app on
  the *second* reload.
- It is **intermittent**: after that, 11 further reloads across two conditions did
  not reproduce it.
- **A location-related hypothesis was tested and NOT confirmed.** `getUserRegion`'s
  `withTimeout` resolves `null` after 5s but never cancels the underlying
  `getCurrentPositionAsync`, so on a simulator with no fix an Expo async call stays
  pending forever — which would explain a teardown crash exactly like this one.
  Setting a simulator location gave 6 clean reloads; but **clearing it again also
  gave 5 clean reloads**, so the correlation did not hold up. Treat this as an
  open lead, not the cause.

  (The abandoned-promise behaviour is a **genuine defect regardless** — a native
  call left pending forever is a leak, and it is reachable in production by any
  user indoors or in a tunnel. Worth fixing on its own merits; see the Notes.)

## Reproduction harness

Drive **reloads**, not cold launches — reloads are what reproduced it. macOS blocks
`osascript` keystrokes, so send the reload over Metro's dev-server websocket instead
of trying to synthesise Cmd+R:

```bash
D=$(xcrun simctl list devices booted -j | python3 -c "import json,sys;print([x['udid'] for v in json.load(sys.stdin)['devices'].values() for x in v][0])")
reload() { node --input-type=module -e "
import WebSocket from 'ws';
const ws=new WebSocket('ws://localhost:8081/message');
ws.on('open',()=>{ws.send(JSON.stringify({version:2,method:'reload'}));setTimeout(()=>{ws.close();process.exit(0)},800)});
ws.on('error',()=>process.exit(1));" >/dev/null 2>&1; }

for i in $(seq 1 10); do
  reload; sleep 9
  echo "$i → $(ls ~/Library/Logs/DiagnosticReports | grep -ci reelmap) crashes | alive: $(xcrun simctl spawn $D launchctl list | grep -c pet.one.reelmap)"
done
```

`alive: 0` is the signal — the app is gone, and every later reload is a no-op against
a dead process, so read the FIRST transition, not the final count.

Read a report with: the `.ips` file is two JSON documents separated by a newline — parse
the second for `threads[?triggered].frames` and map `imageIndex` through `usedImages`.

## Notes

**Fix the abandoned promise irrespective of the crash.** `src/lib/location.ts`'s
`withTimeout` resolves the wrapper but abandons `getCurrentPositionAsync`. Expo has no
cancel for the one-shot call, so the cancellable shape is `watchPositionAsync` + take
the first fix + `subscription.remove()` on fix-or-timeout. That removes the
pending-forever call whether or not it turns out to be this crash's trigger.

Bumping `expo` itself (57.0.4 → 57.0.9) changes `expo-modules-core`, so **every** native
module must move together and the dev client must be rebuilt — this is not a
`--save-exact` patch of one package. Expect a full `npx expo run:ios`.

## Log

- **2026-07-30** — Filed. Skew flagged in T-100; escalated after the ExpoModulesCore
  async-bridge crash above.
- **2026-07-31** — **DONE**, merged as PR #149. Root cause confirmed by BISECTION, which
  is what the two earlier hypotheses lacked: the commit before T-100 (`13e3a1a`, verified
  to contain no location code in the bundle) survives 8 reloads clean; every build after
  it dies on the second. T-100 had hit a dyld symbol-not-found from expo-location 57.0.7
  vs expo-modules-core 57.0.3 and pinned expo-location **down** to 57.0.2 — silencing the
  load-time symptom while leaving a live Swift ABI mismatch in exactly the internal types
  in the crash stack. It pinned the wrong side.

  Aligned the whole set (expo →57.0.9, expo-modules-core →57.0.8, expo-location →57.0.7,
  react-native →0.86.2, +14). `expo install --check` now clean. 15 reloads, no crash.

  **Two traps for next time.** (1) `expo install --fix` exits non-zero on a dynamic
  `app.config.ts` but the install still applies — check package.json rather than trusting
  the exit code. It also duplicates dev tools into `dependencies`. (2) The first 15-reload
  soak came back clean and was **worthless**: the app sat on a red "[Worklets] Mismatch
  between JavaScript code version and Worklets Babel plugin version (0.10.1 vs 0.10.0)"
  screen the whole time, so it could not crash because it never ran app code. A dependency
  upgrade invalidates Metro's Babel transform cache — restart with `--clear` and
  **screenshot the app to confirm it is actually rendering before trusting any soak**.

  The abandoned-`getCurrentPositionAsync` lead recorded above was **not** the cause; it was
  a genuine leak and shipped separately as PR #150.
