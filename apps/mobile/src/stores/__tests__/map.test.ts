import { useMapStore } from '@/stores/map';

beforeEach(() => useMapStore.getState().clearFilters());

it('toggles the follow/mine scope on and off', () => {
  useMapStore.getState().setScope('following');
  expect(useMapStore.getState().filters.filter).toBe('following');

  // Selecting the same scope again clears it.
  useMapStore.getState().setScope('following');
  expect(useMapStore.getState().filters.filter).toBeNull();

  useMapStore.getState().setScope('mine');
  expect(useMapStore.getState().filters.filter).toBe('mine');
});

it('makes list and scope mutually exclusive', () => {
  useMapStore.getState().setList({ id: '3', name: 'Trip' });
  useMapStore.getState().setScope('following');
  // Choosing a scope drops the list…
  expect(useMapStore.getState().filters.list).toBeNull();
  expect(useMapStore.getState().filters.filter).toBe('following');

  // …and choosing a list drops the scope.
  useMapStore.getState().setList({ id: '4', name: 'Other' });
  expect(useMapStore.getState().filters.filter).toBeNull();
  expect(useMapStore.getState().filters.list?.id).toBe('4');
});
