// https://docs.expo.dev/guides/using-eslint/
const { defineConfig } = require('eslint/config');
const expoConfig = require('eslint-config-expo/flat');
const { LEGACY_UNTOKENIZED } = require('./eslint.tokens-legacy');

/**
 * Style properties that must come from `src/theme/tokens.ts` (T-104).
 *
 * Colour is deliberately absent — `useColors()` already owns it and nothing
 * hard-codes a hex. These are where the drift actually was: 20 distinct font
 * sizes, 15 radii, and spacing one-offs at 3/5/7/9/13.
 */
const TOKENIZED_PROPS = [
  'fontSize',
  'borderRadius',
  'padding',
  'paddingTop',
  'paddingRight',
  'paddingBottom',
  'paddingLeft',
  'paddingHorizontal',
  'paddingVertical',
  'gap',
  'rowGap',
  'columnGap',
].join('|');

const TOKEN_RULE = {
  'no-restricted-syntax': [
    'error',
    {
      // `[value>=0]` rather than a regex: esquery only applies regex attribute
      // matching to STRING values, so a regex here silently matches nothing —
      // which is exactly how a lint rule ends up passing while enforcing zero.
      selector: `Property[key.name=/^(${TOKENIZED_PROPS})$/] > Literal[value>0]`,
      message:
        'Use a token from @/theme/tokens (space / radius / type) instead of a raw number. ' +
        'If no step fits, the scale is wrong — change the scale, do not add a one-off.',
    },
  ],
};

module.exports = defineConfig([
  expoConfig,
  {
    // Generated output. `ios/` is Expo prebuild output (`expo prebuild --clean`
    // rewrites it), `android/` the same when it exists; `.expo/` and
    // `expo-env.d.ts` are expo-router's generated types; the rest are build
    // artefacts.
    //
    // Every entry is also in .gitignore, and it is deliberately NOT derived from
    // it. The two lists fail in opposite directions: a missing entry here lints
    // generated output, which is noise you can see; a .gitignore broadened for a
    // git reason (it has happened) would silently SHRINK what gets linted, which
    // is the T-114 bug one layer down. They also mean different things — a
    // git-ignored `src/generated/` would still need linting.
    ignores: [
      'dist/**',
      'build/**',
      'coverage/**',
      'web-build/**',
      'ios/**',
      'android/**',
      '.expo/**',
      'expo-env.d.ts',
    ],
  },
  {
    // Stated, not inherited. `--max-warnings=0` in the lint script is what makes
    // a dead `eslint-disable` fail the build — but only because ESLint 9 happens
    // to default this to 'warn'. That is two upstream facts holding up a rule
    // this repo relies on (T-114 found three dead directives in jest.setup.ts,
    // each reading as "a rule is watching this" when none was).
    linterOptions: { reportUnusedDisableDirectives: 'error' },
  },
  {
    // Applied to EVERYTHING by default, so a NEW file is covered without anyone
    // remembering to opt it in. The exemption below is the inverse of an
    // allowlist: it names the files that predate the tokens, and it shrinks as
    // they migrate. Never add a new file to it.
    files: ['app/**/*.{ts,tsx}', 'src/**/*.{ts,tsx}'],
    rules: TOKEN_RULE,
  },
  // Spread conditionally: flat config rejects an empty `files` array, and this
  // list is meant to reach zero.
  ...(LEGACY_UNTOKENIZED.length > 0
    ? [{ files: LEGACY_UNTOKENIZED, rules: { 'no-restricted-syntax': 'off' } }]
    : []),
  {
    // The tokens themselves are where the raw numbers are allowed to live.
    files: ['src/theme/tokens.ts'],
    rules: { 'no-restricted-syntax': 'off' },
  },
]);
