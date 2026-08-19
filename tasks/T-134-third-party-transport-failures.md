# T-134 — Two third-party clients document resilience they do not have

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-031 (Meilisearch search), T-027 (devices + push notifications)
- **Target paths:** `apps/api/app/Services/Search/SearchService.php`,
  `apps/api/app/Services/Push/ExpoPushClient.php`,
  `apps/api/tests/Feature/Search/`, `apps/api/tests/Feature/Push/`

Code review 2026-08-19, findings **CR-7** and **CR-8**. Two files, one shape: a
client whose comment promises it degrades, which throws instead, because the
catch was written for the wrong exception.

## Context

### CR-7 — a Meilisearch outage 500s the Search tab

`SearchService.php:87` catches only `ApiException` with
`errorCode === 'index_not_found'`. A timeout, DNS failure or refused connection
raises `Meilisearch\Exceptions\CommunicationException`, **caught nowhere in
`app/`**.

With the search server unreachable, every keystroke in the Search tab (a public
route at 120/min) returns a 500 and is forwarded to the error tracker as a genuine
server error.

The failure mode is known to the codebase: `UserDataPurger.php:85` explicitly
try/catches *"a Meilisearch outage"*. Only the user-facing path was left out, and
no test covers it.

### CR-8 — ExpoPushClient throws despite promising it never does

The class docblock:

> Never throws on a transport error... a failed batch yields empty tickets and
> the caller moves on.

The file contains **zero catch blocks**. `Http::post()` returns a `Response` only
for HTTP-level errors; a timeout or refused connection throws
`ConnectionException`, so the `! $response->successful()` fallback never runs for
the exact failure it was written for. Every other HTTP caller gets this right —
`YouTubeAdapter:68`, `UrlCanonicalizer:72`, `HostedTranscriber:39`.

Consequence: `POST /api/v1/follows` with `exp.host` unreachable writes the
database notification, then the queued job throws into `failed_jobs`; the push is
never delivered, and a retry re-runs `DatabaseChannel` on the same notification.

## Implementation

- Catch `CommunicationException` / `TimeOutException` alongside the existing
  branch in `SearchService`, return empty results with a degraded flag, and keep
  it out of the error tracker.
- Wrap both `post()` calls in `ExpoPushClient` in
  `try/catch (ConnectionException)`, returning the
  `['status' => 'error', 'details' => ['error' => 'transport']]` tickets the
  docblock **already specifies**.

## Acceptance criteria

- [ ] `GET /search` with Meilisearch unreachable returns 200 with empty results
      and a degraded flag — asserted for a thrown `CommunicationException` **and
      separately** for a timeout, since they are different exception types
- [ ] The degraded response is not reported to the error tracker as a server error
- [ ] `ExpoPushClient` returns the documented error tickets when the transport
      throws — asserted at **both** `post()` call sites
- [ ] `POST /api/v1/follows` with `exp.host` unreachable writes the database
      notification and does **not** land the job in `failed_jobs`
- [ ] Both docblocks now describe code that exists

## Gotchas

- The degraded search flag is a **response-shape change**. Check whether the
  search payload has a contract schema before adding a field; if it does,
  regenerate. Do not invent a new envelope — T-105's `ApiResponse` helper is the
  existing one.
- "Degraded" must be distinguishable from "no results". A user who searched for
  something that genuinely does not exist and a user whose search server is down
  should not read the same screen.
- Second call site, second catch: the review noted **two** `post()` calls in
  `ExpoPushClient` (`:53` and `:95`). Fixing one is the same mistake in miniature.
