/**
 * Generates TypeScript types from the canonical JSON Schemas.
 *
 *   npm run generate -w packages/contracts
 *
 * Inputs: extraction.schema.json (the pipeline contract) plus every
 * schemas/*.json API payload schema. The output (src/generated/*.ts) is
 * COMMITTED; the mobile app and the CI drift check depend on it. Never
 * hand-edit a generated file — edit the schema and regenerate.
 */
import { mkdirSync, readdirSync, writeFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { compileFromFile } from 'json-schema-to-typescript';

const ROOT = join(__dirname, '..');
const SCHEMA = join(ROOT, 'extraction.schema.json');
const SCHEMAS_DIR = join(ROOT, 'schemas');
// Output dir is overridable so the drift test can generate to a temp dir
// without clobbering the committed types.
const OUT_DIR = process.env.CONTRACTS_OUT_DIR ?? join(ROOT, 'src', 'generated');
// Back-compat with the original single-file override used by the drift test.
const EXTRACTION_OUT = process.env.CONTRACTS_OUT ?? join(OUT_DIR, 'extraction.ts');

const BANNER = (source: string) => `/**
 * GENERATED — do not edit; run \`npm run generate\` in packages/contracts.
 * Source of truth: packages/contracts/${source}
 */
`;

async function compileSchema(file: string, source: string): Promise<string> {
  const body = await compileFromFile(file, {
    additionalProperties: false,
    bannerComment: '',
    style: { singleQuote: true },
    cwd: dirname(file),
  });
  return BANNER(source) + body;
}

async function main(): Promise<void> {
  mkdirSync(OUT_DIR, { recursive: true });
  mkdirSync(dirname(EXTRACTION_OUT), { recursive: true });

  writeFileSync(EXTRACTION_OUT, await compileSchema(SCHEMA, 'extraction.schema.json'));
  // eslint-disable-next-line no-console
  console.log(`Wrote ${EXTRACTION_OUT}`);

  const apiSchemas = readdirSync(SCHEMAS_DIR).filter((f) => f.endsWith('.json')).sort();
  for (const file of apiSchemas) {
    const stem = basename(file, '.json').replace(/[^a-z0-9-]/gi, '');
    const out = join(OUT_DIR, `${stem}.ts`);
    if (out === EXTRACTION_OUT) {
      throw new Error(`schemas/${file} collides with the extraction output file`);
    }
    writeFileSync(out, await compileSchema(join(SCHEMAS_DIR, file), `schemas/${file}`));
    // eslint-disable-next-line no-console
    console.log(`Wrote ${out}`);
  }
}

if (require.main === module) {
  main().catch((err) => {
    // eslint-disable-next-line no-console
    console.error(err);
    process.exit(1);
  });
}
