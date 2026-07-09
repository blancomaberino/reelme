/**
 * Generates TypeScript types from the canonical JSON Schema.
 *
 *   npm run generate -w packages/contracts
 *
 * The output (src/generated/extraction.ts) is COMMITTED; the mobile app and the
 * CI drift check depend on it. Never hand-edit the generated file — edit the
 * schema and regenerate.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { compileFromFile } from 'json-schema-to-typescript';

const ROOT = join(__dirname, '..');
const SCHEMA = join(ROOT, 'extraction.schema.json');
// Output path is overridable so the drift test can generate to a temp file
// without clobbering the committed types.
const OUT = process.env.CONTRACTS_OUT ?? join(ROOT, 'src', 'generated', 'extraction.ts');

const BANNER = [
  '/**',
  ' * GENERATED — do not edit; run `npm run generate` in packages/contracts.',
  ' * Source of truth: packages/contracts/extraction.schema.json',
  ' */',
  '',
].join('\n');

export async function generate(): Promise<string> {
  const body = await compileFromFile(SCHEMA, {
    additionalProperties: false,
    bannerComment: '',
    style: { singleQuote: true },
  });
  return BANNER + body;
}

async function main(): Promise<void> {
  const output = await generate();
  mkdirSync(dirname(OUT), { recursive: true });
  writeFileSync(OUT, output);
  // eslint-disable-next-line no-console
  console.log(`Wrote ${OUT}`);
}

if (require.main === module) {
  main().catch((err) => {
    // eslint-disable-next-line no-console
    console.error(err);
    process.exit(1);
  });
}
