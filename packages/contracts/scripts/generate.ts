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
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { compile } from 'json-schema-to-typescript';

/**
 * Strip `minItems`/`maxItems` from every array in the schema (in memory only —
 * the committed `.json` keeps them for PHP validation). TypeScript types don't
 * need array-length bounds, and json-schema-to-typescript expands them into
 * fixed-length TUPLE unions (`[] | [T] | [T, T] | …`) that inline the item type
 * — with enums nested across `places`×`vibe_tags`×`dietary_tags`×`dishes` this
 * exploded the generated file to ~260k lines. Dropping the bounds yields a clean
 * `T[]` and shrinks the output ~1000×.
 */
function stripArrayBounds(node: unknown): void {
  if (Array.isArray(node)) {
    node.forEach(stripArrayBounds);
  } else if (node !== null && typeof node === 'object') {
    const obj = node as Record<string, unknown>;
    delete obj.minItems;
    delete obj.maxItems;
    for (const value of Object.values(obj)) {
      stripArrayBounds(value);
    }
  }
}

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
  const schema = JSON.parse(readFileSync(file, 'utf8')) as Record<string, unknown>;
  stripArrayBounds(schema);
  const name = typeof schema.title === 'string' ? schema.title : basename(file, '.json');
  const body = await compile(schema, name, {
    additionalProperties: false,
    bannerComment: '',
    style: { singleQuote: true },
    // Trailing separator so relative `$ref`s (e.g. place.json → place-source.json)
    // resolve against the schema's own directory.
    cwd: `${dirname(file)}/`,
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
