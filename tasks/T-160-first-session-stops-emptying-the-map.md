# T-160 — Registering stops making the map emptier

- **Phase:** GROW · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-159
- **Target paths:**
  `apps/mobile/app/(main)/map.tsx`,
  `apps/mobile/app/(auth)/welcome.tsx`,
  `apps/mobile/app/(auth)/register.tsx`,
  `apps/mobile/src/i18n/es.ts`

## Why

`map.tsx` derives `filter: filters.list ? null : authed ? 'mine' : null`. A guest
browses the public map -- a city full of pins. The moment registration completes,
`authed` flips and every pin disappears. Creating an account makes the product
worse, and there is no empty state for it: the only 'nothing here' handling is a
fetch-error chip, above a code comment that names the problem exactly ("the
alternative is an empty map that looks exactly like 'you have no places'").

The one empty-state string that does exist, `es.ts:39`
`'Comparte un link o guarda un lugar para empezar tu coleccion.'`, is tuteo and
"link" in an app that is voseo everywhere else ("Guarda", "Comparti", "Todavia no
tenes listas"). It is also the first sentence a new user reads.

## Acceptance

- A newly registered user with zero places keeps seeing the public map; the switch to `filter=mine` happens on their first save -- both sides asserted
- The map has an empty state distinct from the fetch-error chip, and it offers the two real next actions (share a link, add a curated map)
- Three starter pins are presented and tappable; a test presses one and asserts the place is saved and the banner advances
- `es.ts` empty-state copy is voseo and uses 'enlace'; no tuteo verb forms remain in the file
- The OS location prompt is preceded by an in-app explanation and never fires on cold start unprompted (relates to T-146)

## Notes

Filed 2026-09-03 from the growth review's first-session trace. Depends on T-159 so the empty state can offer a curated map as the second fill route rather than only 'go share something'.
