import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import Ajv, { type ErrorObject } from 'ajv';
import addFormats from 'ajv-formats';
import type { ReelmapExtraction } from '../src';

const ROOT = join(__dirname, '..');

function readJson(rel: string): unknown {
  return JSON.parse(readFileSync(join(ROOT, rel), 'utf8'));
}

const schema = readJson('extraction.schema.json') as object;
const validExample = readJson('examples/valid-extraction.json');
const invalidExample = readJson('examples/invalid-extraction.json');

// Compiled once — the schema is static. draft-07 is Ajv's default mode (NOT
// Ajv2020). Formats enabled on both sides (see the PHP round-trip test) so
// `website: format uri` behaves the same.
const ajv = new Ajv({ allErrors: true, strict: false });
addFormats(ajv);
const validate = ajv.compile(schema);

describe('extraction.schema.json (draft-07)', () => {
  it('accepts the canonical valid fixture', () => {
    const ok = validate(validExample);
    expect(validate.errors).toBeNull();
    expect(ok).toBe(true);
  });

  it('rejects the invalid fixture with the expected error paths', () => {
    const ok = validate(invalidExample);
    expect(ok).toBe(false);

    const errors = (validate.errors ?? []) as ErrorObject[];

    // 1) missing top-level `confidence`
    expect(errors).toContainEqual(
      expect.objectContaining({ keyword: 'required', params: { missingProperty: 'confidence' } }),
    );
    // 2) extra top-level property rejected by additionalProperties: false
    expect(errors).toContainEqual(
      expect.objectContaining({
        keyword: 'additionalProperties',
        params: { additionalProperty: 'unexpected_top_level_key' },
      }),
    );
    // 3) frame_refs value out of bounds (max 11)
    expect(errors).toContainEqual(
      expect.objectContaining({
        keyword: 'maximum',
        instancePath: '/evidence/frame_refs/0',
      }),
    );
  });

  it('is a draft-07 schema with the canonical $id', () => {
    expect((schema as Record<string, unknown>).$schema).toBe('http://json-schema.org/draft-07/schema#');
    expect((schema as Record<string, unknown>).$id).toBe('https://reelmap.app/contracts/extraction.schema.json');
  });

  it('typechecks the valid fixture against the generated ReelmapExtraction type', () => {
    // Compile-time guarantee: the fixture is assignable to the generated type.
    const typed = validExample as ReelmapExtraction;
    expect(typed.place.name).toBe('Lanzhou Beef Noodle House');
    expect(typed.confidence.overall).toBeCloseTo(0.91);
  });

  it('has no drift between the schema and the committed generated types', () => {
    // Regenerate to a temp file in a subprocess (keeps json-schema-to-typescript's
    // ESM formatter out of Jest's VM) and diff against the committed output.
    const out = join(mkdtempSync(join(tmpdir(), 'contracts-')), 'extraction.ts');
    execFileSync('npx', ['tsx', 'scripts/generate.ts'], {
      cwd: ROOT,
      env: { ...process.env, CONTRACTS_OUT: out },
      stdio: 'pipe',
    });
    const fresh = readFileSync(out, 'utf8');
    const committed = readFileSync(join(ROOT, 'src/generated/extraction.ts'), 'utf8');
    expect(fresh).toBe(committed); // run `npm run generate -w packages/contracts` if this fails
  }, 60000);
});
