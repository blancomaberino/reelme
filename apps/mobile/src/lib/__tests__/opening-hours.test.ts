import {
  CLARA_CAFE,
  LA_DIECISIETE,
  SCHEMA_ORG,
  SCHEMA_ORG_SPEC,
  SPANISH,
} from '@/test/opening-hours-fixtures';

import { hourLines } from '../opening-hours';

describe('hourLines', () => {
  it('returns unknown-with-no-lines for null / undefined / empty', () => {
    expect(hourLines(null)).toEqual([]);
    expect(hourLines(undefined)).toEqual([]);
    expect(hourLines([])).toEqual([]);
  });

  it('renders schema.org rule lines verbatim too — both writer branches', () => {
    expect(hourLines(SCHEMA_ORG)).toEqual(SCHEMA_ORG);
    expect(hourLines(SCHEMA_ORG_SPEC)).toEqual(SCHEMA_ORG_SPEC);
  });

  it('preserves the thin and narrow-no-break spaces Google actually sends', () => {
    // The characters the docblock on `hourLines` argues make parsing a guess.
    // Asserted on the OUTPUT, not the fixture, so a `.replace(/\s/g, ' ')` or a
    // normalizing "cleanup" inside `hourLines` turns this red — the one
    // mutation that would otherwise be invisible in every other test here,
    // because ' ' and '\u2009' are indistinguishable on screen and in a diff.
    const friday = hourLines(LA_DIECISIETE)[4];
    expect(friday).toContain('\u2009'); // THIN SPACE, around the en dash
    expect(friday).toContain('\u202f'); // NARROW NO-BREAK SPACE, before PM/AM
    expect(friday).toBe(LA_DIECISIETE[4]);
  });

  it("keeps a Spanish source's own wording rather than translating or reordering it", () => {
    expect(hourLines(SPANISH)).toEqual(SPANISH);
  });

  it('preserves source order rather than sorting it', () => {
    // LA_DIECISIETE is Monday-first, which is NOT alphabetical — so a `.sort()`
    // anywhere in the pipeline fails here. This is also the regression test for
    // the original bug: this exact shape used to yield an empty list, because
    // the old code read `.periods` off what is a plain array.
    const weekly = hourLines(LA_DIECISIETE);
    expect(weekly).toEqual(LA_DIECISIETE);
    expect(weekly).not.toEqual([...LA_DIECISIETE].sort());
    expect(hourLines(CLARA_CAFE)).toEqual(CLARA_CAFE);
  });

  it('adds nothing of its own — no summary line, no open/closed verdict', () => {
    // The output must be the source's lines and NOTHING else. Asserted as an
    // exact set difference rather than `openNow === null`, because the old
    // wrapper's always-null field could not fail a test: reintroduce a summary
    // row or a computed "Open now"/"Closed" line and this turns red, which is
    // the guarantee that actually matters to the screen.
    for (const lines of [LA_DIECISIETE, CLARA_CAFE, SCHEMA_ORG, SPANISH, ['Open 24 hours']]) {
      expect(hourLines(lines)).toEqual(lines);
    }
  });

  it('keeps duplicate lines instead of collapsing them', () => {
    // Two shut days read identically once the day name is stripped upstream;
    // de-duplicating here would silently delete a day the source did send.
    const lines = ['Closed', 'Closed', 'Tuesday: 8:30 AM – 8:00 PM'];
    expect(hourLines(lines)).toEqual(lines);
  });

  it('trims surrounding whitespace and drops blank lines', () => {
    expect(hourLines(['  Monday: Closed  ', '', '   '])).toEqual(['Monday: Closed']);
  });

  it('never throws on junk that slips past the contract at runtime', () => {
    // A response cached before the shape was pinned is exactly this case.
    const junk = [null, 42, { periods: [] }, undefined, 'Tuesday: 8:30 AM – 8:00 PM'] as unknown as string[];
    expect(() => hourLines(junk)).not.toThrow();
    expect(hourLines(junk)).toEqual(['Tuesday: 8:30 AM – 8:00 PM']);

    const notAnArray = { periods: [], weekday_text: ['Monday: Closed'] } as unknown as string[];
    expect(hourLines(notAnArray)).toEqual([]);
  });
});
