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
 *     `additionalProperties: true` is the same hole spelled out, so the rule
 *     is the VALUE, not the key: presence alone would let a future author
 *     write `true` and keep this file green.
 *  3. An object with `properties` but no `required` makes every field optional,
 *     so a renamed or dropped field validates fine. `required: []` says the
 *     same thing in more characters — hence non-empty, again a check on the
 *     value rather than the key.
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

/**
 * The four rules, as predicates over a single subschema: `true` means the node
 * VIOLATES the rule. Named and exported to the suite below so each one can be
 * fired at a synthetic offender — a structural guard nobody has watched fail
 * is worth as little as the union it was written to catch.
 */
export const violates = {
  openArray: (n: Node): boolean => typesOf(n).includes('array') && !('items' in n),

  openObject: (n: Node): boolean =>
    typesOf(n).includes('object') && 'properties' in n && n.additionalProperties !== false,

  allOptional: (n: Node): boolean =>
    typesOf(n).includes('object') &&
    'properties' in n &&
    !(Array.isArray(n.required) && n.required.length > 0),

  objectArrayUnion: (n: Node): boolean =>
    typesOf(n).includes('object') && typesOf(n).includes('array'),
};

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
      .filter(([, n]) => violates.openArray(n))
      .map(([path]) => path);
    expect(open).toEqual([]);
  });

  it('closes every object with `additionalProperties: false`, so an unlisted field cannot ride along', () => {
    // The value, not the key: `true` — or a schema-valued `additionalProperties`
    // alongside declared `properties` — reopens exactly the hole this forbids.
    // A map (`additionalProperties: {…}` and NO `properties`) is a different,
    // legitimate shape and is not a subject here; its value schema is walked
    // on its own.
    const open = typed
      .filter(([, n]) => violates.openObject(n))
      .map(([path]) => path);
    expect(open).toEqual([]);
  });

  it('declares a non-empty `required` on every object, so a dropped field is not silently optional', () => {
    // `required: []` is indistinguishable from no `required` at all: every
    // field stays optional and a rename still validates.
    const open = typed
      .filter(([, n]) => violates.allOptional(n))
      .map(([path]) => path);
    expect(open).toEqual([]);
  });

  it('never unions object WITH array — that is the opening_hours defect verbatim', () => {
    const ambiguous = typed
      .filter(([, n]) => violates.objectArrayUnion(n))
      .map(([path]) => path);
    expect(ambiguous).toEqual([]);
  });
});

/**
 * The rules fired at synthetic offenders.
 *
 * The corpus suite above passes when the corpus is clean — which is also what
 * it does when a rule has stopped working. These cases are the other half: each
 * asserts the rule flags the shape it exists to catch AND leaves the correct
 * shape alone. Two of them are regressions in their own right: `openObject` and
 * `allOptional` used to test for the mere PRESENCE of the key, so
 * `additionalProperties: true` and `required: []` — the same holes, spelled out
 * — sailed through the guard that claimed to forbid them.
 */
describe('the rules themselves', () => {
  it('openArray flags an array with no `items`, and passes one with them', () => {
    expect(violates.openArray({ type: 'array' })).toBe(true);
    expect(violates.openArray({ type: 'array', items: { type: 'string' } })).toBe(false);
  });

  it('openObject flags `additionalProperties: true` — presence of the key is not the rule', () => {
    const properties = { name: { type: 'string' } };
    expect(violates.openObject({ type: 'object', properties })).toBe(true);
    expect(violates.openObject({ type: 'object', properties, additionalProperties: true })).toBe(true);
    expect(violates.openObject({ type: 'object', properties, additionalProperties: false })).toBe(false);
  });

  it('openObject flags declared `properties` alongside a schema-valued `additionalProperties`', () => {
    // Known fields plus typed extras is still an open shape: a payload may
    // carry names the contract never lists.
    const node = {
      type: 'object',
      properties: { name: { type: 'string' } },
      additionalProperties: { type: 'number' },
    };
    expect(violates.openObject(node)).toBe(true);
  });

  it('openObject leaves a map alone — no `properties`, so it is not a closed-object subject', () => {
    // `extraction.schema.json`'s `confidence.per_field`: a dictionary of
    // dotted-path -> 0..1. Legitimately open; its value schema is walked
    // separately by `subschemas`.
    const map = { type: 'object', additionalProperties: { type: 'number', minimum: 0, maximum: 1 } };
    expect(violates.openObject(map)).toBe(false);
  });

  it('allOptional flags `required: []` — an empty list makes every field optional', () => {
    const properties = { name: { type: 'string' } };
    expect(violates.allOptional({ type: 'object', properties })).toBe(true);
    expect(violates.allOptional({ type: 'object', properties, required: [] })).toBe(true);
    expect(violates.allOptional({ type: 'object', properties, required: ['name'] })).toBe(false);
  });

  it('objectArrayUnion flags the opening_hours defect verbatim', () => {
    expect(violates.objectArrayUnion({ type: ['object', 'array', 'null'] })).toBe(true);
    expect(violates.objectArrayUnion({ type: ['array', 'null'], items: { type: 'string' } })).toBe(false);
  });

  it('every rule ignores a node with no `type` — a $ref carries none of its own', () => {
    const ref = { $ref: 'place-source.json' };
    for (const [name, rule] of Object.entries(violates)) {
      expect([name, rule(ref)]).toEqual([name, false]);
    }
  });
});
