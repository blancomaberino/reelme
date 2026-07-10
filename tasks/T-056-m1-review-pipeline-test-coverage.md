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
