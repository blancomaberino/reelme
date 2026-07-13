import type { OpeningHours } from '@/api/places';

export type HoursSummary = {
  /** True when a period covers `now`; null when hours are unknown. */
  openNow: boolean | null;
  /** Human line, e.g. "Open now · closes 23:00" or "Closed". Null when unknown. */
  label: string | null;
  /** Seven "Mon: 09:00 – 23:00" rows for the expandable list (empty when unknown). */
  weekly: string[];
};

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** "0930" (Google period time) → "09:30". Tolerant of already-coloned input. */
function fmtTime(time: string): string {
  const digits = time.replace(/\D/g, '');
  if (digits.length < 3) return time;
  return `${digits.slice(0, 2)}:${digits.slice(2, 4)}`;
}

/** Minutes-since-Sunday-00:00 for a (day, "HHMM") pair. */
function toWeekMinutes(day: number, time: string): number {
  const digits = time.replace(/\D/g, '').padStart(4, '0');
  const h = Number(digits.slice(0, 2));
  const m = Number(digits.slice(2, 4));
  return day * 1440 + h * 60 + m;
}

/**
 * Summarize Google-style opening hours for the place detail screen (T-033).
 *
 * Uses the device timezone (acceptable for M2 — the place's true local zone
 * isn't captured yet). Tolerates absent/malformed periods and windows that
 * span midnight (close.day < open.day, or a 24h period with no close). Never
 * throws — a bad payload yields `{openNow: null}`.
 */
export function summarizeHours(hours: OpeningHours | null | undefined, now: Date = new Date()): HoursSummary {
  const periods = hours?.periods;
  if (!Array.isArray(periods) || periods.length === 0) {
    // Fall back to weekday_text if that's all Google gave us.
    const weekly = Array.isArray(hours?.weekday_text) ? hours!.weekday_text! : [];
    return { openNow: null, label: null, weekly };
  }

  const nowMinutes = now.getDay() * 1440 + now.getHours() * 60 + now.getMinutes();
  const week = 7 * 1440;

  let openNow = false;
  let closesAt: string | null = null;

  for (const period of periods) {
    if (!period?.open || typeof period.open.day !== 'number' || typeof period.open.time !== 'string') {
      continue;
    }
    const start = toWeekMinutes(period.open.day, period.open.time);
    // No close ⇒ the Google 24/7 sentinel (a single day-0 00:00 period with no
    // close): open the whole week, so any `now` matches. Close before open ⇒
    // wraps past midnight.
    let end = period.close ? toWeekMinutes(period.close.day, period.close.time) : start + week;
    if (end <= start) end += week;

    // Test `now` and `now + 1 week` so a Sun-night→Mon-morning window matches
    // regardless of which side of the week boundary `now` sits.
    for (const candidate of [nowMinutes, nowMinutes + week]) {
      if (candidate >= start && candidate < end) {
        openNow = true;
        closesAt = period.close ? fmtTime(period.close.time) : null;
      }
    }
  }

  const weekly = buildWeekly(periods, hours);
  const label = openNow
    ? closesAt
      ? `Open now · closes ${closesAt}`
      : 'Open now'
    : 'Closed';

  return { openNow, label, weekly };
}

function buildWeekly(periods: NonNullable<OpeningHours['periods']>, hours: OpeningHours | null | undefined): string[] {
  if (Array.isArray(hours?.weekday_text) && hours!.weekday_text!.length > 0) {
    return hours!.weekday_text!;
  }

  const byDay = new Map<number, string[]>();
  for (const period of periods) {
    if (!period?.open || typeof period.open.day !== 'number') continue;
    const open = fmtTime(period.open.time);
    const close = period.close ? fmtTime(period.close.time) : '24h';
    const line = period.close ? `${open} – ${close}` : 'Open 24 hours';
    const list = byDay.get(period.open.day) ?? [];
    list.push(line);
    byDay.set(period.open.day, list);
  }

  return DAYS.map((name, day) => {
    const windows = byDay.get(day);
    return `${name}: ${windows && windows.length > 0 ? windows.join(', ') : 'Closed'}`;
  });
}
