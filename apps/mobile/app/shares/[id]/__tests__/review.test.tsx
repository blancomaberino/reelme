import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Alert } from 'react-native';

import ReviewScreen from '../review';
import { reviewShare } from '@/test/share-fixtures';
import { api } from '@/api/client';

import { mockRouter } from '../../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

const lastPatchBody = () => JSON.parse(mock.history.patch.at(-1)!.data);

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
  mockRouter.replace.mockClear();
  mockRouter.params = { id: '1' };
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('prefills the form and tints only the low-confidence field', async () => {
  mock.onGet('/shares/1').reply(200, { data: reviewShare() });

  render(<ReviewScreen />, { wrapper: Providers });

  const name = await screen.findByLabelText('Name');
  expect(name.props.value).toBe('La Gran Burger');
  // category prefilled + selected
  expect(screen.getByLabelText('Restaurant').props.accessibilityState.selected).toBe(true);
  // cuisines flattened to a comma list
  expect(screen.getByLabelText('Cuisines').props.value).toBe('burgers');
  // Only `address.city` is below the confidence threshold (0.4) → one hint.
  expect(screen.getAllByText('Low confidence — worth a check')).toHaveLength(1);
});

it('publishes the edited extraction, re-geocoding when the pin is untouched', async () => {
  mock.onGet('/shares/1').reply(200, { data: reviewShare() });
  mock.onPatch('/shares/1').reply(200, { data: reviewShare({ status: 'analyzing', failure: null }) });

  render(<ReviewScreen />, { wrapper: Providers });
  fireEvent.changeText(await screen.findByLabelText('Name'), 'La Gran Burger 2');
  fireEvent.press(screen.getByText('Publish'));

  await waitFor(() => expect(mock.history.patch).toHaveLength(1));
  const body = lastPatchBody();
  expect(body.action).toBe('publish');
  expect(body.extraction.places[0].name).toBe('La Gran Burger 2');
  // untouched pin → no forced coordinate; the backend re-geocodes the address.
  expect(body.place_candidate).toBeUndefined();
  await waitFor(() =>
    expect(mockRouter.replace).toHaveBeenCalledWith({ pathname: '/shares/[id]/status', params: { id: '1' } }),
  );
});

it('sends the panned pin as a manual coordinate', async () => {
  mock.onGet('/shares/1').reply(200, { data: reviewShare() });
  mock.onPatch('/shares/1').reply(200, { data: reviewShare({ status: 'analyzing', failure: null }) });

  render(<ReviewScreen />, { wrapper: Providers });
  await screen.findByLabelText('Name');
  // Drag the map under the crosshair — the settled center becomes the pin.
  fireEvent(screen.getByTestId('MapView'), 'regionChangeComplete', {
    latitude: -34.5,
    longitude: -56.0,
    latitudeDelta: 0.01,
    longitudeDelta: 0.01,
  });
  fireEvent.press(screen.getByText('Publish'));

  await waitFor(() => expect(mock.history.patch).toHaveLength(1));
  expect(lastPatchBody().place_candidate).toEqual({ lat: -34.5, lng: -56.0 });
});

it('attaches to a chosen dedupe candidate instead of a new pin', async () => {
  mock.onGet('/shares/1').reply(200, {
    data: reviewShare({
      pending_places: [
        {
          index: 0,
          name: 'La Gran Burger',
          reason: 'ambiguous_place',
          candidates: [
            { place_id: '42', name: 'La Gran Burger (existing)', address: 'Av. Italia 123', distance_m: 50, similarity: 0.8 },
          ],
        },
      ],
    }),
  });
  mock.onPatch('/shares/1').reply(200, { data: reviewShare({ status: 'analyzing', failure: null }) });

  render(<ReviewScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByText('La Gran Burger (existing)'));
  fireEvent.press(screen.getByText('Publish'));

  await waitFor(() => expect(mock.history.patch).toHaveLength(1));
  const body = lastPatchBody();
  expect(body.place_candidate).toEqual({ place_id: 42 });
  // picking a candidate hides the manual pin adjuster
  expect(screen.queryByTestId('MapView')).toBeNull();
});

it('folds edited price, vibe tags and dishes into the payload', async () => {
  mock.onGet('/shares/1').reply(200, { data: reviewShare() });
  mock.onPatch('/shares/1').reply(200, { data: reviewShare({ status: 'analyzing', failure: null }) });

  render(<ReviewScreen />, { wrapper: Providers });
  await screen.findByLabelText('Name');

  fireEvent.press(screen.getByLabelText('$$$')); // price → 3
  fireEvent.press(screen.getByLabelText('Cozy')); // add a vibe tag
  fireEvent.changeText(screen.getByPlaceholderText('Add a dish'), 'Fries');
  fireEvent(screen.getByPlaceholderText('Add a dish'), 'submitEditing');
  fireEvent.press(screen.getByText('Publish'));

  await waitFor(() => expect(mock.history.patch).toHaveLength(1));
  const place = lastPatchBody().extraction.places[0];
  expect(place.price_range).toBe(3);
  expect(place.vibe_tags).toEqual(['casual', 'cozy']); // existing + added
  expect(place.dishes.map((d: { name: string }) => d.name)).toEqual(['Cheeseburger', 'Fries']);
});

it('maps a 422 onto the offending field', async () => {
  mock.onGet('/shares/1').reply(200, { data: reviewShare() });
  mock.onPatch('/shares/1').reply(422, {
    error: { message: 'Validation failed.', details: { 'extraction.places.0.name': ['The name is invalid.'] } },
  });

  render(<ReviewScreen />, { wrapper: Providers });
  await screen.findByLabelText('Name');
  fireEvent.press(screen.getByText('Publish'));

  expect(await screen.findByText('The name is invalid.')).toBeOnTheScreen();
  expect(mockRouter.replace).not.toHaveBeenCalled();
});

it('discards the share and returns to the composer', async () => {
  mock.onGet('/shares/1').reply(200, { data: reviewShare() });
  mock.onDelete('/shares/1').reply(200, {});
  jest.spyOn(Alert, 'alert').mockImplementation((_t, _m, buttons) => {
    buttons?.find((b) => b.style === 'destructive')?.onPress?.();
  });

  render(<ReviewScreen />, { wrapper: Providers });
  await screen.findByLabelText('Name');
  fireEvent.press(screen.getByText('Discard'));

  await waitFor(() => expect(mock.history.delete.some((r) => r.url === '/shares/1')).toBe(true));
  expect(mockRouter.replace).toHaveBeenCalledWith('/(main)/share');
});
