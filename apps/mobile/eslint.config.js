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
      selector: `Property[key.name=/^(${TOKENIZED_PROPS})$/] > Literal[value>=0]`,
      message:
        'Use a token from @/theme/tokens (space / radius / type) instead of a raw number. ' +
        'If no step fits, the scale is wrong — change the scale, do not add a one-off.',
    },
  ],
};

module.exports = defineConfig([
  expoConfig,
  {
    // Generated output, all of it git-ignored (see .gitignore). The lint script
    // lints the whole workspace — `eslint .` — so anything not named here gets
    // linted, which is the right default: the previous command named three
    // directories and silently skipped everything else. The cost of that
    // default is this list, and the cost of getting this list wrong is noise
    // about files nobody can fix in the repo, not a silent pass.
    //
    // `ios/` is Expo prebuild output (`expo prebuild --clean` rewrites it),
    // `android/` the same when it exists; `.expo/` and `expo-env.d.ts` are
    // expo-router's generated types.
    ignores: [
      'dist/*',
      'build/**',
      'coverage/**',
      'ios/**',
      'android/**',
      '.expo/**',
      'expo-env.d.ts',
    ],
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
