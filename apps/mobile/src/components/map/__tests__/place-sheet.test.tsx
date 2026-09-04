import { render, screen } from '@testing-library/react-native';

import type { MapPin } from '@/api/places';
import { OPEN_STATE_MAX_AGE_MS } from '@/lib/opening-hours';
import { useSettingsStore } from '@/stores/settings';

import { PlaceSheet } from '../place-sheet';

/**
 * T-156: the pin sheet is where "can I go there, now" is answered, so this pins
 * BOTH halves of that answer and — more importantly — every way it must decline
 * to answer. The bug this file exists to prevent is not a missing label; it is a
 * confident "Cerrado" on a place that is open, which sends someone away from a
 * restaurant that wanted their business.
 */
function pin(over: Partial<MapPin> = {}): MapPin {
  return {
    type: 'place',
    id: '1',
    name: 'Café Brasilero',
    lat: -34.9,
    lng: -56.16,
    category: null,
    city: 'Montevideo',
    price_range: 2,
    status: 'active',
    tags: [],
    source_count: 1,
    has_active_offer: false,
    thumbnail_url: null,
    top_influencer: null,
    ...over,
  };
}

const noop = () => {};
const OPEN = { open_now: true, closes_at: '23:30', opens_at: null };
const CLOSED = { open_now: false, closes_at: null, opens_at: '19:00' };

/** Fresh: `fetchedAt` = now, so the cue is inside its trust window. */
const show = (p: MapPin) => render(<PlaceSheet pin={p} fetchedAt={Date.now()} onViewPlace={noop} />);

beforeEach(() => {
  useSettingsStore.setState({ locale: 'en' });
});

it('renders the distance and the open cue when the server sent both', () => {
  show(pin({ distance_m: 450, open_state: OPEN }));

  expect(screen.getByText('450 m')).toBeTruthy();
  expect(screen.getByText('Open · closes 23:30')).toBeTruthy();
});

it('renders kilometres for a far place, in the locale number style', () => {
  useSettingsStore.setState({ locale: 'es' });
  show(pin({ distance_m: 3_240, open_state: null }));

  // Not "3240 m" — the venue picker's inline formatter would have said exactly
  // that, which is why both call sites now share one. And a comma, not a dot.
  expect(screen.getByText('3,2 km')).toBeTruthy();
  expect(screen.queryByText('3240 m')).toBeNull();
  expect(screen.queryByText('3.2 km')).toBeNull();
});

it('renders NEITHER when the request carried no viewer position', () => {
  // Absent, not zero and not false — the API omits both keys without `near`.
  show(pin());

  expect(screen.queryByTestId('place-sheet-status')).toBeNull();
  expect(screen.queryByTestId('place-sheet-distance')).toBeNull();
  // The rest of the sheet is untouched: this is an omission, not a broken card.
  expect(screen.getByText('Café Brasilero')).toBeTruthy();
  expect(screen.getByText('Montevideo')).toBeTruthy();
});

it('shows distance alone when the hours are unknowable — and never invents "Closed"', () => {
  // The central rule (T-155, re-asserted here). `open_state: null` means nobody
  // KNOWS, and the one thing a diner must never be told is that a place is shut
  // when nobody knows.
  show(pin({ distance_m: 450, open_state: null }));

  expect(screen.getByText('450 m')).toBeTruthy();
  expect(screen.queryByText('Closed')).toBeNull();
  expect(screen.queryByText('Closed · opens 19:00')).toBeNull();
  expect(screen.queryByText(/Open/)).toBeNull();
});

it('shows the cue alone when the distance is missing', () => {
  // The two fields are independent: a payload can carry one without the other,
  // and dropping both because one is absent would be a second bug.
  show(pin({ open_state: CLOSED }));

  expect(screen.getByText('Closed · opens 19:00')).toBeTruthy();
  expect(screen.queryByTestId('place-sheet-distance')).toBeNull();
});

it('says "Closed" honestly when the server actually decided it is closed', () => {
  // The mirror of the rule above: refusing to claim anything is only right when
  // there is nothing to claim. A cue that never appears is as useless as a wrong
  // one, so the negative assertions above must not pass by rendering nothing.
  show(pin({ distance_m: 120, open_state: CLOSED }));

  expect(screen.getByText('Closed · opens 19:00')).toBeTruthy();
});

it('drops the cue AND the distance once the payload is stale', () => {
  // The map query is persisted for 24h, so a cold start with no network can
  // repaint an 11-hour-old "Open · closes 23:30" at nine the next morning.
  //
  // This test used to assert the distance SURVIVED, and defended it with "a
  // place does not move". The place doesn't; a distance has two endpoints and
  // the viewer is the one that moves. Pins fetched in Montevideo last night,
  // opened this morning in Buenos Aires with no signal: the cue withdrew and
  // "450 m" stayed on screen for a restaurant 200 km away, with no refetch
  // coming — `near` is deliberately not in the cache key, so nothing
  // invalidates it offline.
  render(
    <PlaceSheet
      pin={pin({ distance_m: 450, open_state: OPEN })}
      fetchedAt={Date.now() - (OPEN_STATE_MAX_AGE_MS + 60_000)}
      onViewPlace={noop}
    />,
  );

  expect(screen.queryByText('Open · closes 23:30')).toBeNull();
  expect(screen.queryByText('450 m')).toBeNull();
  // The whole row goes, rather than leaving an empty band under the name.
  expect(screen.queryByTestId('place-sheet-status')).toBeNull();
});

it('shows neither half when the caller does not say how old the data is', () => {
  // `fetchedAt` defaults to 0 — the epoch, i.e. an enormous age. That is the
  // honest default: a caller that forgot to pass it has no idea how stale its
  // payload is either, so it must not be allowed to assert freshness by omission.
  render(<PlaceSheet pin={pin({ distance_m: 450, open_state: OPEN })} onViewPlace={noop} />);

  expect(screen.queryByText('Open · closes 23:30')).toBeNull();
  expect(screen.queryByText('450 m')).toBeNull();
});

it('still shows the distance on a FRESH payload — the gate is staleness, not the field', () => {
  // The positive control for the two above. Without it, deleting the distance
  // entirely would pass them both.
  render(
    <PlaceSheet
      pin={pin({ distance_m: 450, open_state: OPEN })}
      fetchedAt={Date.now()}
      onViewPlace={noop}
    />,
  );

  expect(screen.getByText('450 m')).toBeTruthy();
  expect(screen.getByText('Open · closes 23:30')).toBeTruthy();
});
