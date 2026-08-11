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
      ['grep', '-l', '-E', '^[[:space:]]*<Marker[[:space:]>/]', '--', 'app', 'src'],
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

  const hits = output
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .filter((file) => !ALLOWED.includes(file))
    .filter((file) => !file.includes('__tests__'));

  expect(hits).toEqual([]);
});
