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

- [ ] `npx expo install --check` reports no outdated packages for the mobile app
- [ ] The iOS dev client is rebuilt against the aligned versions; `Podfile.lock` committed
- [ ] jest / tsc / lint stay green after the bump; breaking changes are handled, not pinned around
- [ ] The app survives **10 consecutive cold launches** with no new entry in
      `~/Library/Logs/DiagnosticReports` (see the harness below)
- [ ] If the crash still reproduces after alignment, it is written up — minimal repro plus
      an upstream issue link — rather than closed silently

## Reproduction harness

```bash
D=$(xcrun simctl list devices booted -j | python3 -c "import json,sys;print([x['udid'] for v in json.load(sys.stdin)['devices'].values() for x in v][0])")
for i in $(seq 1 10); do
  xcrun simctl terminate $D pet.one.reelmap >/dev/null 2>&1
  sleep 1; xcrun simctl launch $D pet.one.reelmap >/dev/null 2>&1; sleep 7
  echo "$i → $(ls ~/Library/Logs/DiagnosticReports | grep -ci reelmap) crash reports"
done
```

Read a report with: the `.ips` file is two JSON documents separated by a newline — parse
the second for `threads[?triggered].frames` and map `imageIndex` through `usedImages`.

## Notes

Bumping `expo` itself (57.0.4 → 57.0.9) changes `expo-modules-core`, so **every** native
module must move together and the dev client must be rebuilt — this is not a
`--save-exact` patch of one package. Expect a full `npx expo run:ios`.

## Log

- **2026-07-30** — Filed. Skew flagged in T-100; escalated after the ExpoModulesCore
  async-bridge crash above.
