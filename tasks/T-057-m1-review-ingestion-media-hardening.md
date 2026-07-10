# T-057 — M1 review follow-up: ingestion/media hardening

- **Phase:** M1 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-016, T-009
- **Target paths:** `apps/api/app/Http/Controllers/MediaUploadController.php`, `apps/api/app/Services/Media/MediaPaths.php`, `apps/api/app/Services/Ingestion/UrlCanonicalizer.php`
- **Spec refs:** [07-risks-decisions.md#risk-register](../07-risks-decisions.md)

## Context

Deferred low-severity hardening from the PR #10 review. None of these are exploitable
in production today (the upload route below is dev-only; the SSRF guard is already
pinned for the common case), but they're worth closing when ingestion work resumes.

## Items

1. **`MediaUploadController` size cap is bypassable** — the 413 check trusts the
   client `Content-Length` header, and `$request->getContent()` buffers the whole
   body into memory regardless. A chunked/absent `Content-Length` sails past the cap.
   Fix: stream to disk (`Storage::putStream`) with a hard byte cap on the stream, and
   reject a missing `Content-Length`. Note: this route is **not registered in
   production** (production uses presigned R2 uploads), so severity is low.
2. **`MediaPaths::original()` does not sanitize `$ext`** — `$ext` is only
   `ltrim($ext, '.')` before being interpolated into the object key. Sibling methods
   use fixed extensions, so this is the only user-influenceable one. If a caller ever
   derives `$ext` from a filename/mime, separators would survive into the path. Harden
   with e.g. `preg_replace('/[^a-z0-9]+/i', '', ltrim($ext, '.'))`.
3. **SSRF pin — all resolved IPs / IPv6** — `UrlCanonicalizer` now pins each redirect
   target to the *first* validated IP via `CURLOPT_RESOLVE` (closes the DNS-rebinding
   TOCTOU). Consider: validating and being able to fall through all A/AAAA records, and
   confirm/document the IPv6 pin format. Not a known bypass — defence in depth.

## Acceptance

- Upload route streams with a hard cap and rejects missing `Content-Length`.
- `MediaPaths::original()` cannot emit path separators from `$ext`.
- SSRF pin behaviour across multiple/IPv6 records reviewed and documented; tests stay
  network-free.
