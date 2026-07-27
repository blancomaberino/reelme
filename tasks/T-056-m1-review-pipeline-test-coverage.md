# T-056 — M1 review follow-up: ingestion pipeline failure/retry test coverage

- **Phase:** M1 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-016
- **Target paths:** `apps/api/tests/Feature/Shares/`, `apps/api/tests/Unit/Adapters/`, `apps/api/tests/Feature/Media/`
- **Spec refs:** [04-analysis-pipeline.md#overview](../04-analysis-pipeline.md)

## Context

Deferred from the review of PR #10 (the M1 API foundation, T-007…T-016). The
happy paths and the blocking bugs found in review are now tested, but a multi-agent
test review surfaced real gaps in the **off-nominal** ingestion paths — the ones the
"share → failed → retry" story depends on. All of these must run with fakes/fixtures,
no live network (repo rule).

## Items (each currently untested)

1. **`FetchSourcePost` rate-limit back-off** — `app/Jobs/FetchSourcePost.php` `FetchFailed`
   with `retryAfter !== null` calls `$this->release($retryAfter)`. `ExceptionTaxonomyTest`
   only constructs the exception; nothing proves the job releases/re-queues and makes no
   state change. Assert: a fake adapter throwing `FetchFailed(retryAfter: N)` releases the job.
2. **`FetchSourcePost` chain-advance** — a `FetchFailed`/`PostUnavailable` with no
   `retryAfter` should advance to the next adapter. Use a chain `[Failing, FakeInstagram]`
   where the first throws and a later one succeeds; assert metadata from the later adapter
   is persisted and the post ends `Fetched`.
3. **`FailsShareOnError::failed()` terminal path** — `app/Jobs/Concerns/FailsShareOnError.php`.
   Assert `(new FetchSourcePost($id))->failed(new Exception)` on a `Fetching` share sets
   `Failed` + `failure_reason`; on an already-`Published` share it is a no-op (no throw).
4. **`UrlCanonicalizer` positive/edge branches** — `app/Services/Ingestion/UrlCanonicalizer.php`.
   Currently only the two SSRF-reject cases + no-network strip are tested. Add (with
   `Http::fake`): a shortlink that legitimately expands to a public platform URL yielding
   the right `externalId`; the `ConnectionException` → return-original branch; relative
   `Location` resolution. Strengthen the existing SSRF asserts to assert the URL equals the
   original shortlink (expansion stopped) rather than merely "does not contain the IP".
5. **`MediaUploadController` 413** and **`ManualUploadAdapter` empty-result** branches —
   the over-cap `Content-Length` → 413 path and `fetchMedia` on a payload-less post
   returning an empty `MediaFetchResult` are both untested.

## Acceptance

- Meaningful tests (assert behaviour + side effects, not just status) for items 1–5.
- Coverage on these paths does not regress; CI-safe (no network).

## Log

**2026-07-26 — IMPLEMENTED (branch `test/T-056-ingestion-failure-retry-coverage`).**
Test-only change; no production code touched. Picked per the ROADMAP rule (ARCH
done → lowest incomplete phase = M1 → ready follow-up; the natural next after
T-057, which hardened the code these tests now exercise). 11 new tests, all
green; full suite **928 passed**, Pint (538 files) + PHPStan L6 clean.

- **New harness:** `tests/Support/ThrowingAdapter` — a `SourceAdapter` whose
  `fetchMetadata`/`fetchMedia` always throw a preconfigured exception. Bound as a
  container instance so `AdapterRegistry` resolves it from a class-string chain
  (the registry `container->make()`s each chain entry). Two file-scoped helpers in
  `IngestPipelineTest.php`: `fakeThrowingInstagramChain($error, then:)` (chain =
  `[ThrowingAdapter]` or `[ThrowingAdapter, FakeInstagramAdapter]`) and
  `fetchingInstagramShare($url)`.

- **Item 1 (rate-limit back-off):** `FetchFailed(retryAfter: 42)` → the job
  `->withFakeQueueInteractions()` + `assertReleased(delay: 42)`, and asserts NO
  state change (post still `Pending`/caption null, share still `Fetching`,
  `failure_reason` null). `withFakeQueueInteractions()` is the idiomatic way to
  assert `$this->release()` on a direct `->handle()` (sets a `FakeJob`;
  `InteractsWithQueue::release()` no-ops without a job).

- **Item 2 (chain-advance):** a `FetchFailed` (no Retry-After) and a
  `PostUnavailable` (no auth) each advance to a later `FakeInstagramAdapter` that
  succeeds → post ends `Fetched` with the fake's caption/influencer; the
  PostUnavailable case also asserts the share is left `Fetching` (DownloadMedia
  runs next), and the FetchFailed case `assertNotReleased()`.

- **Item 3 (`FailsShareOnError::failed()`):** on a `Fetching` share →
  `Failed` + `failure_reason: fetch_unavailable`; on an already-`Published`
  (terminal) share → no-op, no throw, unchanged (`Published` has no outgoing
  transitions so `canTransitionTo(Failed)` is false).

- **Item 4 (`UrlCanonicalizer`):** added a public-target expansion+pin
  (shortlink 301→public IPv4 literal, no DNS), relative-`Location` resolution
  (two-hop: shortlink→public-IP absolute, then a relative `/second` resolved
  against the IP host — literal IP keeps every hop DNS-free), `ConnectionException`
  → return-original, and an in-place `youtu.be` 200 → externalId + tracking
  stripped. Strengthened the two SSRF-reject asserts from
  `not->toContain(<ip>)` to `toBe(<original shortlink>)` (proves expansion
  stopped, not merely that the IP is absent). **NOTE (network-free constraint):**
  `pinnedIp()` does real DNS (`gethostbynamel`) on redirect targets, so a
  hostname target would hit the network in CI — every positive test uses an IP
  literal or an in-place 200, mirroring the existing IPv6 test's approach.

- **Item 5 (`ManualUploadAdapter` empty-result):** `fetchMedia` returns an empty
  `MediaFetchResult` when the post is missing AND when it exists but has no
  screen-recording asset. **The "MediaUploadController 413" half of item 5 was
  already covered** by T-057's `MediaStorageTest` (both the declared-length 413
  and the stream-cap-on-received-bytes 413), so it was not duplicated.

**ON MERGE:** flip tasks.json T-056 → done.
