# T-163 — Shared links stop being dead ends

- **Phase:** GROW · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** nothing
- **Target paths:**
  `apps/mobile/app.config.ts`,
  `apps/api/public/.well-known/`,
  `apps/api/routes/web.php`,
  `apps/api/resources/views/list-share.blade.php`,
  `apps/api/app/Http/Controllers/`

## Why

Every shareable thing the product builds terminates in a blank tap.

`app.config.ts` declares `scheme: 'reelmap'` and nothing else -- no
`ios.associatedDomains`, no Android intent filter, and `apps/api/public/.well-known/`
does not exist. `list-share.blade.php` sets its open-in-app button to
`reelmap://list/{slug}`, which does nothing in WhatsApp's in-app browser and
nothing at all without the app installed.

The same page's head is charset, viewport, and a static
`<title>Reelmap - Lista compartida</title>` -- identical for every list ever
shared -- and the list is client-fetched, so a crawler sees an empty document. A
list pasted into WhatsApp renders a grey box that reads like spam.

Lists, public slugs, save-a-copy, invitations and public influencer maps are all
already built. This is the task that lets any of them travel. The repo already
knows the pattern: `web.php` bounces `/connect/{outcome}` from HTTPS into a
`reelmap://` deep link for Stripe. It was applied to payments and never to
sharing.

## Acceptance

- `apple-app-site-association` and `assetlinks.json` are served at their exact paths, with the right content-type, HTTP 200 and no redirect -- asserted by tests
- `app.config.ts` declares `ios.associatedDomains` and an `autoVerify` Android intent filter for the https host
- `/l/{slug}` server-renders `<title>`, `og:title`, `og:description` and `og:image` from the list; a test asserts them in the returned HTML, with JavaScript never executed
- An https list URL opens the app when installed (Maestro flow) and the web page when not
- An unknown, private or unlisted slug renders a safe page that leaks no list contents and no existence signal beyond what the API already returns
- The deep-link target is validated against the same URL guard the push handler uses (relates to T-137, T-138)

## Notes

Filed 2026-09-03 from the growth review (blocker B2). This is the multiplier on every distribution item: invites, creator maps, escrow claim pages and story cards are all structurally zero-conversion until it ships.
