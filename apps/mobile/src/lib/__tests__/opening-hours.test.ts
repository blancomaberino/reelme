import { summarizeHours } from '../opening-hours';

/**
 * Fixtures are copied VERBATIM from the dev database (T-128), not invented:
 * Google `weekday_text` for real Montevideo places. Note the en dash (U+2013),
 * the "Closed" rows, the two-window rows, and the opening times that omit
 * their meridiem — the four properties that make parsing these lines a guess.
 */
const LA_DIECISIETE = [
  'Monday: Closed',
  'Tuesday: 12:00 – 4:00 PM, 8:00 PM – 12:00 AM',
  'Wednesday: 12:00 – 4:00 PM, 8:00 PM – 12:00 AM',
];
const CLARA_CAFE = ['Monday: Closed', 'Tuesday: 8:30 AM – 8:00 PM'];
/** schema.org rule lines, as `WebsiteBusinessSource` writes them. */
const SCHEMA_ORG = ['Mo-Fr 09:00-17:00', 'Sa,Su 10:00-14:00'];
/** A source that answered in Spanish — the launch market's other half. */
const SPANISH = ['lunes: Cerrado', 'martes: 12:00 – 16:00, 20:00 – 00:00'];

describe('summarizeHours', () => {
  it('returns unknown-with-no-lines for null / undefined / empty', () => {
    expect(summarizeHours(null)).toEqual({ openNow: null, weekly: [] });
    expect(summarizeHours(undefined)).toEqual({ openNow: null, weekly: [] });
    expect(summarizeHours([])).toEqual({ openNow: null, weekly: [] });
  });

  it('renders Google weekday_text lines verbatim, in order', () => {
    // The regression the screen shipped with for months: this shape produced
    // `weekly: []` because the old code read `.periods` off an array.
    expect(summarizeHours(LA_DIECISIETE).weekly).toEqual(LA_DIECISIETE);
  });

  it('renders schema.org rule lines verbatim too', () => {
    expect(summarizeHours(SCHEMA_ORG).weekly).toEqual(SCHEMA_ORG);
  });

  it("keeps a Spanish source's own wording rather than translating or reordering it", () => {
    expect(summarizeHours(SPANISH).weekly).toEqual(SPANISH);
  });

  it('never claims open or closed from text, whatever the lines say', () => {
    // Every fixture, including one whose FIRST line literally reads "Closed":
    // `null` means unknown, and the screen must not paint it as shut.
    for (const lines of [LA_DIECISIETE, CLARA_CAFE, SCHEMA_ORG, SPANISH]) {
      expect(summarizeHours(lines).openNow).toBeNull();
    }
    expect(summarizeHours(['Open 24 hours']).openNow).toBeNull();
  });

  it('keeps duplicate lines instead of collapsing them', () => {
    // Two shut days read identically once the day name is stripped upstream;
    // de-duplicating here would silently delete a day the source did send.
    const lines = ['Closed', 'Closed', 'Tuesday: 8:30 AM – 8:00 PM'];
    expect(summarizeHours(lines).weekly).toEqual(lines);
  });

  it('trims surrounding whitespace and drops blank lines', () => {
    expect(summarizeHours(['  Monday: Closed  ', '', '   ']).weekly).toEqual(['Monday: Closed']);
  });

  it('never throws on junk that slips past the contract at runtime', () => {
    // A response cached before the shape was pinned is exactly this case.
    const junk = [null, 42, { periods: [] }, undefined, 'Tuesday: 8:30 AM – 8:00 PM'] as unknown as string[];
    expect(() => summarizeHours(junk)).not.toThrow();
    expect(summarizeHours(junk).weekly).toEqual(['Tuesday: 8:30 AM – 8:00 PM']);

    const notAnArray = { periods: [], weekday_text: ['Monday: Closed'] } as unknown as string[];
    expect(summarizeHours(notAnArray)).toEqual({ openNow: null, weekly: [] });
  });
});
