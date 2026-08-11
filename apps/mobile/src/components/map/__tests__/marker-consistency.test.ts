import { execFileSync } from 'node:child_process';

/**
 * Every map in the app draws the same pin.
 *
 * This is a grep, not a render test, because the failure it guards against is
 * not a wrong render — it is a NEW screen quietly rendering its own thing. Six
 * map surfaces existed and five had each grown a bare `<Marker>`: the
 * platform's default red pin, against the main map's photo bubble. Nobody
 * introduced that on purpose; `PlaceMarker` simply demanded a viewport `MapPin`
 * while those screens are fed by list endpoints, so each one took the path of
 * least resistance and the divergence was invisible from inside any single file.
 *
 * A render test on the screens that exist today cannot catch the seventh one.
 */
const ALLOWED = [
  // The shared markers themselves — these ARE the implementations.
  'src/components/map/place-marker.tsx',
  'src/components/map/cluster-marker.tsx',
  'src/components/map/offer-marker.tsx',
];

it('uses the shared markers everywhere — no screen renders its own <Marker>', () => {
  // `git grep` so the check follows the repo rather than a hand-maintained
  // file list. execFileSync with an argument array: no shell, so nothing here
  // is interpolated into one — and git grep exits 1 on "no matches", which is
  // a PASS, not an error.
  let output = '';
  try {
    output = execFileSync(
      'git',
      // Anchored to the start of a line so it matches JSX and not PROSE: the
      // first version hit three files whose only crime was a comment saying
      // "PlaceMarker, not a bare <Marker>". A guard that flags its own
      // documentation gets weakened until it flags nothing.
      //
      // `|$` because every <Marker in this repo is written multi-line — the
      // props start on the NEXT line. Requiring a character after `Marker`
      // matched zero files, so the guard passed unconditionally for the whole
      // time it was on main; the mutation check that "proved" it only ever
      // exercised mini-map's one-line self-closing form.
      //
      // `--untracked` because the seventh divergent screen is an UNTRACKED new
      // file at the moment its author runs the suite. Without it the guard goes
      // green on the violation and only bites after the commit — i.e. after the
      // review gate it exists to feed.
      ['grep', '--untracked', '-l', '-E', '^[[:space:]]*<Marker([[:space:]>/]|$)', '--', 'app', 'src'],
      // FOUR levels: __tests__ → map → components → src → apps/mobile. Three
      // landed in src/, where the `app` and `src` pathspecs do not exist, so
      // the grep matched nothing and the test passed no matter what. A mutation
      // check caught it; the assertion below cannot tell "clean" from "looked
      // in the wrong place", which is why the path is spelled out here.
      { cwd: `${__dirname}/../../../../`, encoding: 'utf8' },
    );
  } catch (error) {
    const status = (error as { status?: number }).status;
    if (status !== 1) throw error; // 1 = no matches; anything else is a real failure
  }

  // POSITIVE CONTROL, before the assertion. `expect(hits).toEqual([])` is
  // satisfied by a grep that found nothing for ANY reason, and that has now
  // happened twice: once from the wrong cwd, once from a regex that could not
  // match the repo's own JSX. Neither was visible at runtime. If the shared
  // markers themselves stop matching, the search is broken, not the codebase.
  for (const allowed of ALLOWED) {
    expect(output).toContain(allowed);
  }

  const hits = output
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .filter((file) => !ALLOWED.includes(file))
    .filter((file) => !file.includes('__tests__'));

  expect(hits).toEqual([]);
});
