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
 * So this plants a known violation in two places and asserts the real CI
 * command fails AND names both files:
 *
 *   - `src/` — inside the old command's reach. Catches a lint setup that is
 *     broken outright.
 *   - the workspace root — outside it. Catches a regression to `expo lint`, or
 *     to any command that lints a hand-listed set of directories.
 *
 * Asserting the paths matters as much as asserting the exit code: a repo with a
 * pre-existing error would fail lint anyway, and a self-test that accepts that
 * as proof is testing nothing.
 */
import { spawnSync } from 'node:child_process';
import { rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const MOBILE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const REPO_ROOT = resolve(MOBILE_ROOT, '..', '..');

// `no-var` is an eslint-config-expo error and applies everywhere, unlike the
// token rule, which is scoped to app/ and src/ and so cannot probe the root.
const VIOLATION = [
  '// Temporary file written by scripts/verify-lint-gate.mjs. Delete it.',
  'export var lintGateProbe = 1;',
  '',
].join('\n');

const PROBES = [join(MOBILE_ROOT, 'src', 'lint-gate-probe.ts'), join(MOBILE_ROOT, 'lint-gate-probe.ts')];

function cleanup() {
  for (const probe of PROBES) rmSync(probe, { force: true });
}

// A probe left behind by an interrupted run makes the tree dirty and breaks
// lint and tsc for whoever hits it next, so Ctrl-C during the ~10s lint run must
// not leak one. These handlers do that, but NOT by running: `spawnSync` blocks
// the event loop, so a handler cannot fire until the lint child exits — by which
// point `finally` has already cleaned up. What they buy is the deferral itself.
// Merely REGISTERING a handler stops Node taking the signal's default action, so
// the process survives to reach `finally` instead of being killed mid-spawn.
//
// Verified both ways: interrupt the run at 1s and it finishes normally with no
// probe left; delete these five lines and the same interrupt exits 130 with both
// probes on disk. (An interrupted run therefore still runs to completion. That
// is the trade — a slow Ctrl-C over a dirty checkout.)
for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
  process.on(signal, () => {
    cleanup();
    process.exit(130);
  });
}

cleanup();

let result;
try {
  for (const probe of PROBES) writeFileSync(probe, VIOLATION);

  // The exact string CI runs (.github/workflows/ci.yml, the mobile job), not a
  // reconstruction of it — the point is to test the wiring, not eslint.
  result = spawnSync('npm', ['run', 'lint', '-w', 'apps/mobile'], {
    cwd: REPO_ROOT,
    encoding: 'utf8',
  });
} finally {
  cleanup();
}

const output = `${result.stdout ?? ''}${result.stderr ?? ''}`;
const failures = [];

// spawn failed outright (no npm on PATH) — `status` is null, not a number, and
// reporting that as "the gate does not bite" would send someone hunting eslint.
if (result.error) {
  console.error(`✗ Could not run the lint command: ${result.error.message}`);
  process.exit(1);
}

if (result.status === 0) {
  failures.push('the lint command exited 0 with two deliberate violations in the tree');
}

for (const probe of PROBES) {
  if (!output.includes(probe)) {
    failures.push(`the lint command never reported ${probe} — that path is outside what it lints`);
  }
}

if (failures.length > 0) {
  console.error('✗ The lint gate does not bite:\n');
  for (const failure of failures) console.error(`  - ${failure}`);
  console.error('\nLint command output:\n');
  console.error(output.trim() || '(no output at all)');
  process.exit(1);
}

console.log('✓ Lint gate verified: exits non-zero and reports violations at the workspace root and in src/.');
