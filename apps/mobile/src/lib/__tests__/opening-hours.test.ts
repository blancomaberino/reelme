import { summarizeHours } from '../opening-hours';

// A fixed "now": Wednesday (day 3), 14:30 device-local.
const wed1430 = new Date(2026, 6, 15, 14, 30); // 2026-07-15 is a Wednesday

describe('summarizeHours', () => {
  it('returns unknown for null / empty hours', () => {
    expect(summarizeHours(null)).toEqual({ openNow: null, label: null, weekly: [] });
    expect(summarizeHours({ periods: [] })).toEqual({ openNow: null, label: null, weekly: [] });
    expect(summarizeHours(undefined)).toEqual({ openNow: null, label: null, weekly: [] });
  });

  it('reports open + closing time inside a period', () => {
    const s = summarizeHours(
      { periods: [{ open: { day: 3, time: '0900' }, close: { day: 3, time: '2300' } }] },
      wed1430,
    );
    expect(s.openNow).toBe(true);
    expect(s.label).toBe('Open now · closes 23:00');
  });

  it('reports closed outside any period', () => {
    const s = summarizeHours(
      { periods: [{ open: { day: 3, time: '1800' }, close: { day: 3, time: '2300' } }] },
      wed1430,
    );
    expect(s.openNow).toBe(false);
    expect(s.label).toBe('Closed');
  });

  it('handles a window spanning midnight into the next day', () => {
    // Tue 20:00 → Wed 02:00; "now" Wed 01:00 must read as open.
    const wed0100 = new Date(2026, 6, 15, 1, 0);
    const s = summarizeHours(
      { periods: [{ open: { day: 2, time: '2000' }, close: { day: 3, time: '0200' } }] },
      wed0100,
    );
    expect(s.openNow).toBe(true);
    expect(s.label).toBe('Open now · closes 02:00');
  });

  it('handles the Sunday/Saturday week-boundary wrap', () => {
    // Sat 22:00 → Sun 04:00; "now" Sun 02:00.
    const sun0200 = new Date(2026, 6, 19, 2, 0); // 2026-07-19 is a Sunday
    const s = summarizeHours(
      { periods: [{ open: { day: 6, time: '2200' }, close: { day: 0, time: '0400' } }] },
      sun0200,
    );
    expect(s.openNow).toBe(true);
  });

  it('treats a period with no close as open 24h', () => {
    const s = summarizeHours({ periods: [{ open: { day: 3, time: '0000' } }] }, wed1430);
    expect(s.openNow).toBe(true);
    expect(s.label).toBe('Open now');
  });

  it('builds a seven-day weekly list', () => {
    const s = summarizeHours(
      { periods: [{ open: { day: 3, time: '0900' }, close: { day: 3, time: '2300' } }] },
      wed1430,
    );
    expect(s.weekly).toHaveLength(7);
    expect(s.weekly[3]).toBe('Wed: 09:00 – 23:00');
    expect(s.weekly[0]).toBe('Sun: Closed');
  });

  it('prefers weekday_text when present', () => {
    const s = summarizeHours(
      {
        periods: [{ open: { day: 3, time: '0900' }, close: { day: 3, time: '2300' } }],
        weekday_text: ['Sunday: Closed', 'Monday: 9 AM–11 PM'],
      },
      wed1430,
    );
    expect(s.weekly).toEqual(['Sunday: Closed', 'Monday: 9 AM–11 PM']);
  });

  it('never throws on malformed periods', () => {
    // @ts-expect-error deliberately malformed
    expect(() => summarizeHours({ periods: [{ open: null }, { foo: 'bar' }] }, wed1430)).not.toThrow();
  });
});
