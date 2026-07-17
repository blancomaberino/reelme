import { useMapStore } from '@/stores/map';

beforeEach(() => useMapStore.getState().clearFilters());

it('toggles a price tier on and off', () => {
  useMapStore.getState().togglePrice(2);
  expect(useMapStore.getState().filters.price_range).toBe(2);

  // Selecting the same tier again clears it.
  useMapStore.getState().togglePrice(2);
  expect(useMapStore.getState().filters.price_range).toBeNull();
});

it('toggles tags additively', () => {
  useMapStore.getState().toggleTag('ramen');
  useMapStore.getState().toggleTag('sushi');
  expect(useMapStore.getState().filters.tags).toEqual(['ramen', 'sushi']);

  useMapStore.getState().toggleTag('ramen');
  expect(useMapStore.getState().filters.tags).toEqual(['sushi']);
});

it('setting a list clears any residual scope filter; clearing it restores none', () => {
  // T-071: the home map is always personal (filter derived in map.tsx from
  // auth, not stored). A saved list overrides that scope while active.
  useMapStore.getState().setList({ id: '4', name: 'Trip' });
  expect(useMapStore.getState().filters.list?.id).toBe('4');
  expect(useMapStore.getState().filters.filter).toBeNull();

  useMapStore.getState().setList(null);
  expect(useMapStore.getState().filters.list).toBeNull();
});

it('toggles a payment card, replacing the prior one (single-select)', () => {
  useMapStore.getState().toggleCard('Santander');
  expect(useMapStore.getState().filters.card).toBe('Santander');
  useMapStore.getState().toggleCard('Visa');
  expect(useMapStore.getState().filters.card).toBe('Visa');
  useMapStore.getState().toggleCard('Visa');
  expect(useMapStore.getState().filters.card).toBeNull();
});

it('clearFilters resets everything', () => {
  useMapStore.getState().togglePrice(3);
  useMapStore.getState().toggleTag('brunch');
  useMapStore.getState().toggleCard('Santander');
  useMapStore.getState().clearFilters();
  expect(useMapStore.getState().filters).toEqual({
    cuisine: null,
    price_range: null,
    card: null,
    tags: [],
    list: null,
    filter: null,
  });
});
