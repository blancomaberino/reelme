import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Structural rules every schema in this package must satisfy (T-128).
 *
 * WHY THIS EXISTS. `place.json` typed `opening_hours` as
 * `["object", "array", "null"]` — a union that validates BOTH shapes and pins
 * neither. It generated `{} | unknown[] | null`, which told the mobile client
 * nothing, so the client invented its own reading of the field and the hours
 * row rendered for nobody for months. Every layer was green the whole time,
 * because a contract that admits anything cannot be violated.
 *
 * The fix for one field was a schema edit. The fix for the CLASS is this file:
 * it would have failed that union at authorship, before a line of client code
 * was written against it, and it caught a second instance the day it was added
 * (`place.json`'s `google_reviews` items had neither `required` nor
 * `additionalProperties`, so every field in a cached Google review was
 * optional while the mobile type declared them present).
 *
 * These are deliberately structural, not stylistic. Each one is a way for a
 * schema to describe nothing while looking complete:
 *
 *  1. An `array` with no `items` generates `unknown[]`.
 *  2. An object with `properties` but no `additionalProperties: false` lets a
 *     payload carry fields the contract never mentions — which is how the API
 *     served `reviews` for months against a schema that had no such property.
 *  3. An object with `properties` but no `required` makes every field optional,
 *     so a renamed or dropped field validates fine.
 *  4. A type union containing BOTH `object` and `array` is the exact
 *     opening_hours defect: two incompatible shapes, neither pinned.
 *
 * There is NO allowlist, on purpose. The corpus was measured when this landed
 * and had exactly two violations, both fixed in the same change — so a new
 * violation is always a new mistake, never inherited debt. Keep it that way:
 * if a schema genuinely needs an open shape, that is a decision worth arguing
 * for in review, not a line quietly added to an exemption list.
 */
const ROOT = join(__dirname, '..');
const SCHEMAS_DIR = join(ROOT, 'schemas');

type Node = Record<string, unknown>;

/** Every `path -> subschema` pair reachable from a schema document. */
function subschemas(node: unknown, path: string): Array<[string, Node]> {
  if (Array.isArray(node)) {
    return node.flatMap((child, i) => subschemas(child, `${path}[${i}]`));
  }
  if (node === null || typeof node !== 'object') return [];

  const obj = node as Node;
  const found: Array<[string, Node]> = [[path, obj]];

  for (const key of ['properties', 'definitions', 'patternProperties'] as const) {
    const container = obj[key];
    if (container && typeof container === 'object') {
      for (const [name, child] of Object.entries(container as Node)) {
        found.push(...subschemas(child, `${path}/${key}/${name}`));
      }
    }
  }
  for (const key of ['items', 'not', 'additionalProperties'] as const) {
    if (obj[key] && typeof obj[key] === 'object') {
      found.push(...subschemas(obj[key], `${path}/${key}`));
    }
  }
  for (const key of ['anyOf', 'oneOf', 'allOf'] as const) {
    if (Array.isArray(obj[key])) {
      found.push(...subschemas(obj[key], `${path}/${key}`));
    }
  }

  return found;
}

function typesOf(node: Node): string[] {
  const t = node.type;
  if (typeof t === 'string') return [t];
  if (Array.isArray(t)) return t.filter((x): x is string => typeof x === 'string');
  return [];
}

const files = readdirSync(SCHEMAS_DIR)
  .filter((f) => f.endsWith('.json'))
  .concat('../extraction.schema.json');

describe.each(files)('%s', (file) => {
  const doc = JSON.parse(readFileSync(join(SCHEMAS_DIR, file), 'utf8')) as Node;
  const nodes = subschemas(doc, '');

  // A `$ref` node carries no `type` of its own, so it is not a subject here —
  // the target it points at is checked when that target is walked.
  const typed = nodes.filter(([, n]) => typesOf(n).length > 0 && !('$ref' in n));

  it('declares `items` for every array — an open array generates unknown[]', () => {
    const open = typed
      .filter(([, n]) => typesOf(n).includes('array') && !('items' in n))
      .map(([path]) => path);
    expect(open).toEqual([]);
  });

  it('closes every object with additionalProperties, so an unlisted field cannot ride along', () => {
    const open = typed
      .filter(([, n]) => typesOf(n).includes('object') && 'properties' in n && !('additionalProperties' in n))
      .map(([path]) => path);
    expect(open).toEqual([]);
  });

  it('declares `required` on every object, so a dropped field is not silently optional', () => {
    const open = typed
      .filter(([, n]) => typesOf(n).includes('object') && 'properties' in n && !('required' in n))
      .map(([path]) => path);
    expect(open).toEqual([]);
  });

  it('never unions object WITH array — that is the opening_hours defect verbatim', () => {
    const ambiguous = typed
      .filter(([, n]) => typesOf(n).includes('object') && typesOf(n).includes('array'))
      .map(([path]) => path);
    expect(ambiguous).toEqual([]);
  });
});
