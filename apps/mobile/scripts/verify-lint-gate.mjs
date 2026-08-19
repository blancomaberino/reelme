#!/usr/bin/env node
/**
 * Proves the mobile lint gate can actually fail (T-114).
 *
 * The bug this exists to prevent: `npm run lint -w apps/mobile` used to run
 * `expo lint`, which lints `src`, `app` and `components` and NOTHING else. Every
 * root-level file — the five suites in `__tests__/`, `app.config.ts`,
 * `jest.setup.ts`, the jest and eslint configs — was outside the gate, and the
 * gate reported success on all of it. CI reads an exit code; an exit code of 0
 * from a linter that never opened the file is indistinguishable from a pass.
 *
 * So this plants known violations across the workspace, runs the real CI lint
 * command, and asserts it reports every one of them. Three things are checked,
 * because each covers a way the gate could be quietly narrowed:
 *
 *   1. **A probe in every linted directory.** Two probes are not enough: the
 *      `ignores` array in eslint.config.js can silence ANY directory, and a
 *      gate that only watches `src/` and the root reports ✓ while `app/` — 99
 *      screens and routes — is switched off. That is the original bug, reachable
 *      through the config this very task added.
 *   2. **A warning, in a pass of its own.** `no-var` is error-level, so it fires
 *      with or without `--max-warnings=0`, and dropping that flag would silence
 *      expo's whole warn tier (`eqeqeq`, `no-unused-vars`, unused
 *      eslint-disable directives…). A warning has to be planted ALONE to be
 *      observable, because the flag changes the exit code and nothing else.
 *   3. **The diagnostics, not just the paths.** A probe path also appears in an
 *      eslint CRASH message — a non-zero exit and both paths present, with
 *      nothing linted. So the rule ids are counted too.
 */
import { spawnSync } from 'node:child_process';
import { readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const WORKSPACE = 'apps/mobile';
const MOBILE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const REPO_ROOT = resolve(MOBILE_ROOT, '..', '..');

const PROBE_FILE = 'lint-gate-probe.ts';
const LINTABLE = /\.(ts|tsx|js|jsx|mjs|cjs)$/;

/** Every top-level directory the gate must reach. `.` covers the root files. */
const PROBE_DIRS = ['.', 'src', 'app', '__tests__', 'scripts'];

/**
 * Top-level directories that hold lintable files and are deliberately NOT
 * linted — generated output, mirroring `ignores` in eslint.config.js. Kept as
 * an explicit list so that "not probed" and "deliberately not probed" cannot
 * look the same: a new source directory belongs in PROBE_DIRS, and the check
 * below refuses to run until someone says which it is.
 */
const NOT_LINTED = new Set([
  'node_modules',
  'ios',
  'android',
  '.expo',
  'coverage',
  'build',
  'dist',
  'web-build',
]);

const HEADER = `// Temporary file written by ${WORKSPACE}/scripts/verify-lint-gate.mjs. Delete it.`;

/** Error-level (`no-var`): fails the lint command on its own. */
const ERROR_VIOLATION = [HEADER, 'export var lintGateProbe = 1;', ''].join('\n');

/**
 * Warn-level only (`no-unused-vars`). It has to be planted ALONE, in its own
 * pass: printed output is identical with or without `--max-warnings=0` — the
 * flag changes nothing but the exit code, so a warning sitting next to an error
 * proves nothing. (Caught by mutation: asserting the rule id in the combined
 * output passed happily with the flag removed.)
 */
const WARN_VIOLATION = [
  HEADER,
  'export function lintGateProbeWarn() {',
  '  const unusedProbeLocal = 1;',
  '}',
  '',
].join('\n');

const PROBES = PROBE_DIRS.map((dir) => join(MOBILE_ROOT, dir, PROBE_FILE));

function cleanup() {
  for (const probe of PROBES) rmSync(probe, { force: true });
}

/** Does this directory hold anything ESLint would lint? Stops at the first hit. */
function hasLintableFile(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === 'node_modules') continue;
    if (entry.isDirectory()) {
      if (hasLintableFile(join(dir, entry.name))) return true;
    } else if (LINTABLE.test(entry.name)) {
      return true;
    }
  }
  return false;
}

/**
 * The probe list is only as good as its coverage of the workspace. A new
 * top-level source directory that nobody adds here would be unprobed — and an
 * `ignores` entry could then silence it with this gate still green.
 */
function unprobedDirectories() {
  return readdirSync(MOBILE_ROOT, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name)
    .filter((name) => !PROBE_DIRS.includes(name) && !NOT_LINTED.has(name))
    .filter((name) => hasLintableFile(join(MOBILE_ROOT, name)));
}

const unprobed = unprobedDirectories();
if (unprobed.length > 0) {
  console.error(
    `✗ ${unprobed.join(', ')} holds lintable files but is neither probed nor listed as generated.\n` +
      '  Add it to PROBE_DIRS (it should be linted) or to NOT_LINTED (it should not).',
  );
  process.exit(1);
}

// A probe left behind by an interrupted run must go before the writes, and this
// call is LOAD-BEARING, not redundant with `finally`: `writeFileSync` follows a
// symlink and clobbers its target, while `rmSync` unlinks the link itself. This
// is what stops a symlink planted at a probe path from becoming a
// write-anywhere primitive. Do not "simplify" it away.
cleanup();

// Registering a signal handler is what keeps an interrupted run from leaking a
// probe — not the handler body, which cannot run until `spawnSync` returns the
// event loop, by which point `finally` has already cleaned up. Merely having a
// handler stops Node taking the signal's default action, so the process lives
// long enough to reach `finally`. Verified: with these lines, an interrupt
// leaves no probe on disk; without them, the same interrupt leaves both.
for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
  process.on(signal, () => {
    cleanup();
    process.exit(130);
  });
}

function die(message, code = 1) {
  console.error(message);
  process.exit(code);
}

/** Plant `content` at each of `probes`, run the real lint command, clean up. */
function lintWith(probes, content) {
  let result;
  try {
    for (const probe of probes) writeFileSync(probe, content);

    // The exact string CI runs (.github/workflows/ci.yml, the mobile job), not
    // a reconstruction of it — the point is to test the wiring, not eslint.
    result = spawnSync('npm', ['run', 'lint', '-w', WORKSPACE], {
      cwd: REPO_ROOT,
      encoding: 'utf8',
    });
  } finally {
    cleanup();
  }

  // Writing the probes threw (permissions, a read-only tree) — `result` is
  // unset, and reading `.stdout` off it would bury that under a TypeError.
  if (!result) die('✗ Could not plant the probe files; the lint command never ran.');
  if (result.error) die(`✗ Could not run the lint command: ${result.error.message}`);

  // A real Ctrl-C signals the whole process group, so the lint child dies
  // mid-run and its output is truncated. That is an interrupted run, not a
  // broken gate — saying otherwise sends someone hunting an eslint bug that is
  // not there.
  if (result.signal) {
    die(`✗ The lint command was killed by ${result.signal} before finishing — interrupted, not a gate failure.`, 130);
  }

  return { status: result.status, output: `${result.stdout ?? ''}${result.stderr ?? ''}` };
}

const { status, output } = lintWith(PROBES, ERROR_VIOLATION);

const failures = [];

if (status === 0) {
  failures.push(`the lint command exited 0 with ${PROBES.length} deliberate violations in the tree`);
}

for (const probe of PROBES) {
  if (!output.includes(probe)) {
    failures.push(`the lint command never reported ${probe} — that path is outside what it lints`);
  }
}

// Rule ids, because a path can appear in a crash message without being linted.
const errorsReported = (output.match(/no-var/g) ?? []).length;
if (errorsReported < PROBES.length) {
  failures.push(
    `the output names the probe paths but reports only ${errorsReported} no-var violation(s) for ` +
      `${PROBES.length} probes — a path appears in a CRASH message too, with nothing linted`,
  );
}

// Second pass, warn-level violation alone: the only way to observe that the
// command still fails on warnings, since a warning printed beside an error is
// indistinguishable either way. Cheap — the pass above left a warm eslint cache.
const warnOnly = lintWith([PROBES[1]], WARN_VIOLATION);
if (warnOnly.status === 0) {
  failures.push(
    'a warning alone did not fail the lint command — it has lost `--max-warnings=0`, which ' +
      'silences expo\'s whole warn tier (eqeqeq, no-unused-vars, unused eslint-disable directives…)',
  );
}

if (failures.length > 0) {
  console.error('✗ The lint gate does not bite:\n');
  for (const failure of failures) console.error(`  - ${failure}`);
  console.error('\nLint command output:\n');
  console.error(output.trim() || '(no output at all)');
  process.exit(1);
}

console.log(`✓ Lint gate verified: errors reported in all ${PROBES.length} probed directories, and a warning alone still fails.`);
