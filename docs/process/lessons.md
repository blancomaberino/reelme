# Lessons — the cases behind the rules

`CLAUDE.md` states each rule in one line. This file holds the observed case
behind it, so the rule stays short and the evidence stays checkable. Add a case
here when a rule is added or a bug ships green; never delete one to save space.

## Wiring & seams (T-047, 2026-08-04)

Four foundational bugs shipped green in one task. Every one lived in the seam
between the new code and the app, while the tests only looked inside the new code:

- The offers browse shipped with **no entry point** — deep-link only. Tests
  called `render(<Screen/>)`, which bypasses navigation entirely.
- The offers map was a **hand-rolled second MapView** — no controls, Apple POI
  pins showing. The home map already had all of it.
- That map **never re-queried on pan**: dragging to a venue 19 km away asked
  about the user's sofa. The test asserted the first request only.
- `NSCameraUsageDescription` was missing — `app.config.ts` was right, the
  generated `ios/` was stale, and `expo run:ios` does not re-run prebuild.
- `useIsFocused` **crashed on device**, hidden by a jest stub written to make the
  test pass. 809 tests green.
- The react-native-maps mock overwrote every map's `testID`, making the real
  `pin-map` dead and the pan behaviour untestable.

## A rule needs every writer (T-168, 2026-09-03)

The locale gained a consequence ("changing it must re-ask for places"). The rule
went on `setLocale`; `hydrate()` wrote the same field with a bare `set({ locale })`
and skipped it. Four things agreed it was fine: the test drove the setter; mutation
testing only visits the path the test visits; the diff-scoped review could not see
unchanged code whose *meaning* changed; and a comment beside the code asserted a
false premise ("this setter runs on every hydrate"). CodeRabbit found it as an
"outside diff range" comment with no thread.

So: grep for every writer before adding a rule; test over the writers with an
`it.each` plus an assertion the list is complete; brief reviewers on the
*surface* ("this makes X mean Y — find everything that writes X"), not the diff.

## A single fixture cannot tell "it filtered" from "it returned everything" (T-158)

The 200 branches of a new filter test proved only that the endpoint answered.
Any test of a filter needs a row that must be EXCLUDED and an assertion that it was.

## Eight audit rounds (T-156, reviewed 2026-09-03 → 09-07)

Every finding after round four was in `SentryScrubber`, which was not part of the
task's acceptance. Rounds 1–4 each added one more getter after a reviewer found
one more carrier — unbounded. The file converged only once it matched on SHAPE
(`scrubValue` / `scrubBag` plus one generic walk) instead of enumerating SDK
field names. Rule: a third round of findings in the same file means the design
is wrong; stop patching and redesign.

Structural causes of the round count, fixed in the 2026-09-07 process change:
`/simplify` ran *after* the review and rewrote reviewed code; three review stages
ran sequentially (coderabbit fan-out → security-review → agency audit), each a
round trip; every fix commit invalidated both receipts, so each round re-ran
everything; the API suite (~8 min) ran three to five times per PR.

## Comments that assert how other code behaves (T-158, T-168)

T-158 produced five comments in one branch that claimed things about other
files — one crediting `scripts/deploy.sh` with rollback protection it does not
have, one calling the map's 90°-span bbox a bound comparable to a 50 km radius.
Nothing tests a comment. Reviewers must open the named file for every comment
that makes a claim about it.

## A guard that pins the flag, not the behaviour (T-156, 2026-09-08)

Four times in one session, a fix introduced a defect that its own new test was
blind to. The tests were not weak by accident: each one guarded the failure I
was already picturing, and that is exactly what made it useless.

1. **`withoutOverlapping()` is `onOneServer()`.** A log sweep must run on every
   box, because logs are per-machine files. The comment said so; the test
   asserted `$event->onOneServer === false`. But the overlap mutex is
   `sha1(expression + command)` on the SHARED cache store — no host component —
   so one box takes the Redis lock and the rest skip. Two of three review seats
   found it independently. The test pinned the flag I had in mind and was blind
   to the flag with the same effect.
2. **`--hours=0` empties `failed_jobs`.** Replacing a hardcoded `336` with
   `24 * config('logging.channels.daily.days')` looked like removing a literal.
   It coupled two sinks that INVERT the value at zero: Monolog reads `days=0` as
   keep-forever, artisan reads `--hours=0` as `prune(now())` — every row. An
   empty `LOG_DAILY_DAYS=` casts to 0. The sibling command refuses that exact
   input, in a comment naming the empty env; I gave the second consumer the
   opposite failure mode.
3. **The test for (2) could not fail.** `beforeEach` pinned the config to 14,
   then the test computed `24 * $days` and asserted `336` — 336 against 336,
   under a comment claiming it was "derived, not typed".
4. **A `?? 'unknown'` fallback on a variable every path assigns.** PHPStan
   caught it. A default there would have hidden the bug it looked like it was
   preventing.

**The rule.** Assert the observable the code must produce, never the setting
that happens to produce it — `mutexName()`, not `onOneServer`. And if a test
computes its expected value, the computation belongs in production code where a
test can drive it: `App\Support\RetentionWindow` exists so `RetentionWindowTest`
can throw `''`, `0`, `-1`, `'forever'` and `null` at the floor. Deleting the
floor turns six of seven red; the version that lived in the schedule expression
could not be driven at all.

**What caught what.** Every one of these was found before a push — by the audit
seats, twice by two lanes converging, once by PHPStan. None was found by the
gates, and CodeRabbit never saw the code (it reviews at PR open; this was all
written after). The gates now carry
`.claude/skills/gates/checks/vacuous-assertions.sh`, which fails on assertions
that cannot fail — `assertTrue(true)`, `expect(x)->toBe(x)`, `assertSame($a,$a)`.
Its own test asserts the blind spot too: a config-pinned tautology like (3) is
NOT mechanically detectable, and nothing should be trusted to catch it except
putting the arithmetic where a test can move it.

**The gate written to catch this shipped with three of its own.** A Code Reviewer
seat reproduced all three: the quality check sat inside the `tooling` area, so it
never ran on the test files it polices; a grounding pass that CRASHED filled its
own log with the error, and "the log is non-empty" passed; and an absent
`skipped` key is falsy, so a hand-written marker with no log at all read `ok` —
while the honest hatch recorded `skipped`, making the forgery both easier and
cleaner-looking than the documented path. The push guard never read the field,
so none of it was visible where it mattered. It was pulled from the PR rather
than merged: a gate with known false-green paths is worse than no gate, because
it buys confidence. The redesign records what the pass DID (which tools this
diff required, and whether any were missing) rather than that a script wrote a
file, and every one of the three has a case that is red against the version that
shipped. Two fixtures in the guard's own existing suite turned out to be passing
for the wrong reason too — their temp repos did not carry the hook, so the gate
skipped and allowed everything.

**Also:** `git add -A` swept 20 untracked `.claude/agents/*.md` into a commit
twice in the same session — 6,377 lines, into a guard path that changes which
audit seats are required. Stage paths explicitly on a branch with untracked
noise in it.

## The suite that lied three ways (T-158, 2026-09-07)

- Composer's default 300 s `process-timeout` killed the suite mid-run with a
  `ProcessTimedOutException` that reads like a failure. Fixed by
  `disableProcessTimeout` in the `test` script.
- Uncapping removed the only bound; a migration waiting on a lock another suite
  held waited forever. Fixed by `timeout -k 30s 700` around Pest.
- Exit 124 means STOPPED, exit 137 means SIGKILL (a bound escalating or an OOM
  kill). Piping through `tail`/`grep` hides the real exit status.
- Pest flags go after `--` (`composer test -- --filter=X`); the `test` script
  guards `config:clear` with `@no_additional_args` for exactly this.
- Two suites at once share the `testing` DB and fail on `42P01` / `42P07` in an
  unrelated test.

Full mechanics: `apps/api/README.md` → "Running the suite without being lied to".

## The simulator is the owner's environment (T-047, 2026-08-04; T-158)

- `simctl openurl` sets the app's launch URL; Expo Router replays it on every
  reload, so the owner's next Cmd+R lands on the screen you were testing.
  Reported three times, each caused by an agent's own verification. Navigate
  with Maestro (`launchApp` + `tapOn`); `openurl` only for screens with no
  in-app path, followed by `terminate` + plain `launch`.
- `simctl location clear` is a no-op; overwrite with Montevideo `-34.9011,-56.1645`.
- Flying the map persists the viewport.
- `simctl privacy revoke location` does not reach the app; use
  `launchApp: { permissions: { location: never } }` (values `always|inuse|never`;
  `deny` is rejected) and restore in the same flow — Maestro has no `finally`,
  so a flow that dies before the restore leaves the state; check where the app
  comes up afterwards.
- Synthetic clicks (`osascript`) do not register as touches; keystrokes do.
- The habitually booted sim is the 932 pt Pro Max, which hides every clipping
  bug — check an SE-sized screen (~608 pt usable) before calling a layout verified.
- Verify the restore with a Cmd+R screenshot; twice it was reported restored and wasn't.

## Green Jest is not a running app (T-100)

`expo-location` was added; 369 tests passed; the app died with
`Cannot find native module 'ExpoLocation'` because the dev client predated the
dependency. Any new native module or `app.config.ts` plugin change needs
`npx expo prebuild --clean` and a full dev-client build.

## Stale runtimes (2026-07-27, three times in one session)

The queue worker boots Laravel once and keeps it; pipeline code changes need a
worker restart (`dev.sh backend` now cycles it). Metro's transform cache serves
stale JS after a branch switch; `expo start --dev-client --clear` is the cure.
When "the feature isn't working" and the DB says the backend did the right
thing, suspect the runtime before the code.

## Container PHP is a minor ahead of CI (T-106)

`php84` image runs 8.5.x; CI pins 8.4. Pint disagreed on a comment inside a
trait-`use` block. After any Pint fix on a restructured class, re-run the fixer
and confirm it is a no-op.

## Stripe does not serve Uruguay (2026-09-03)

The whole M4 money loop passed against a fake that never disagreed with the
real provider's country list. Three of six review agents found it; no test could.
Check a provider's own coverage before building on a rail. Mercado Pago replaces
it, deferred to the end of the queue.

## CodeRabbit on GitHub reviews once and never again

Auto-review is off for this repo ("Review skipped: manual review required for
this OSS repository", shown GREEN). It reviews when the PR opens; every later
push is unreviewed unless asked with `@coderabbitai review`. Its replies to
threads are recorded as reviews with an empty body at HEAD. Only a review whose
body contains `Actionable comments posted` is a real round:

```bash
gh api repos/<o>/<r>/pulls/<n>/reviews \
  -q '.[] | select(.user.login=="coderabbitai[bot]")
       | select(.body|test("Actionable comments posted")) | .commit_id[0:8]'
```

Findings whose line is outside the diff range live in the review BODY with no
thread. Merging a PR forfeits its pending review; the bot will not review a
closed PR.

## Two repos, one shell (T-101)

`git checkout -b` issued from the plan repo created the branch there while the
app repo stayed on `main`, and the next commits landed on `main`. Use
`git -C ~/Sites/reelmap …` or `cd` explicitly; the symptom is `/coderabbit`
reporting "0 changed files".

## Fixing a finding is a change, and needs the same brief (T-158, 2026-09-09)

T-158 went through two audit rounds, a `/simplify` pass and five `/coderabbit`
panel rounds. Every round found something real — but **three of the defects were
in the previous round's fix**, which is the whole story. The feature had a design
brief; the thirty-odd review findings did not, and each was read as a work order
and edited straight in.

Four habits, and what each cost:

**1. No "every reader and writer" grep before changing a rule.** CLAUDE.md §3 has
asked for this since T-168 and it was skipped on every fix.
- A fourth `RefusalReason` (`imprecise`) was added for two list screens. The map
  branched on the same enum with a trailing `if` chain, so the new case fell into
  its silent `denied` arm — the one control whose stated contract is "never a
  silent no-op" became one, for exactly the users the reason was written for.
- `locateUser` was bounded without listing its three callers; two of them render
  the result and went dark for iOS users with Precise Location off.
- A persistence rule written for `['places','tonight', …]` was not run against the
  other eight `['places', …]` keys; `placesByTag('sources')` still matched.

**2. A mechanism asserted from memory instead of read.** Four defects, one habit:
- A guard compared `DB::transactionLevel()` against the depth captured before the
  call, to catch a framework branch that *restores* that counter before rethrowing.
  Dead code for the case it named. `handleTransactionException()` is eleven lines
  and answers it.
- A test froze the clock with `travelTo()` to prove that four `now()` reads had
  become one. Under a frozen clock those are indistinguishable.
- `fn () => ... $calls++` captures by value, so the replacement clock never moved.
- A fixture built 20 opening periods; `OpeningSchedule::salvage()` slices to 14.

**3. Green-then-mutate instead of red-first.** Most guards this session were
mutated and watched fail. The one that was not is the one that shipped vacuous.
Mutation-after proves the same property and is a step you can skip; writing the
test first makes skipping it impossible, because the red run is the first thing
you see.

**4. A fix written before the bug was reproduced.** A reported `sort=distance`
cursor-precision bug was real as a PHP mechanism and is reachable at continental
range — but `radius_m` is capped at 50 km, which holds the values at 13
significant digits. The fix could not be made to bite and was reverted. Measuring
first would have replaced an hour of work with a comment.

### The loop was a separate mistake

Receipts bind to HEAD. Findings were fixed, receipts recorded, and then
"non-blocking" notes applied on top — each of which invalidated the receipt and
required the panel again. **Batch every finding into one fix commit before
recording anything, or decline it and leave the file alone.** A non-blocking note
acted on after the receipt costs a full round.

### What the rounds were worth

Not nothing, which is why the two-round limit was exceeded deliberately rather
than ignored: the panel found a privacy bug (a discovery query keyed by the
viewer's coordinate persisted to plaintext storage), a CHECK constraint enforcing
half its invariant, and a nightly repair that hid the drift it repaired. The
lesson is not "review less" — it is that a fix reviewed as carelessly as it was
written turns one round into five.
