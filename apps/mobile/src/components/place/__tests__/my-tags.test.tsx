import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import type { MyPlaceTag } from '@/api/places';
import { MyTags } from '@/components/place/my-tags';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

const tag = (id: string, label: string): MyPlaceTag => ({ id, label });

it('renders the viewer\'s private tags as chips', () => {
  render(<MyTags slug="clara" tags={[tag('1', 'visitar a las 5'), tag('2', 'llevar a mamá')]} />, {
    wrapper: Providers,
  });

  expect(screen.getByText('visitar a las 5')).toBeOnTheScreen();
  expect(screen.getByText('llevar a mamá')).toBeOnTheScreen();
});

it('adds a tag via POST /me/places/{slug}/tags', async () => {
  let posted: unknown = null;
  mock.onPost('/me/places/clara/tags').reply((config) => {
    posted = JSON.parse(config.data as string);
    return [201, { data: [tag('9', 'nueva')] }];
  });

  render(<MyTags slug="clara" tags={[]} />, { wrapper: Providers });

  fireEvent.changeText(screen.getByPlaceholderText('e.g. visit at 5pm'), 'nueva');
  fireEvent.press(screen.getByLabelText('Add tag'));

  await waitFor(() => expect(posted).toEqual({ label: 'nueva' }));
});

it('does not submit a blank label', async () => {
  let posted = false;
  mock.onPost('/me/places/clara/tags').reply(() => {
    posted = true;
    return [201, { data: [] }];
  });

  render(<MyTags slug="clara" tags={[]} />, { wrapper: Providers });

  // Empty input → the add button is disabled and fires nothing.
  fireEvent.press(screen.getByLabelText('Add tag'));
  fireEvent.changeText(screen.getByPlaceholderText('e.g. visit at 5pm'), '   ');
  fireEvent.press(screen.getByLabelText('Add tag'));

  await new Promise((r) => setTimeout(r, 30));
  expect(posted).toBe(false);
});

it('clears the input on a successful add', async () => {
  mock.onPost('/me/places/clara/tags').reply(201, { data: [tag('9', 'nueva')] });

  render(<MyTags slug="clara" tags={[]} />, { wrapper: Providers });
  const input = screen.getByPlaceholderText('e.g. visit at 5pm');
  fireEvent.changeText(input, 'nueva');
  fireEvent.press(screen.getByLabelText('Add tag'));

  await waitFor(() => expect(input.props.value).toBe(''));
});

it('keeps the text and shows an error when the add fails', async () => {
  mock.onPost('/me/places/clara/tags').reply(500);

  render(<MyTags slug="clara" tags={[]} />, { wrapper: Providers });
  const input = screen.getByPlaceholderText('e.g. visit at 5pm');
  fireEvent.changeText(input, 'nueva');
  fireEvent.press(screen.getByLabelText('Add tag'));

  expect(await screen.findByText('Couldn’t save that tag. Try again.')).toBeOnTheScreen();
  // The typed text survives so the user can retry.
  expect(input.props.value).toBe('nueva');
});

it('removes a tag via DELETE /me/places/{slug}/tags/{id}', async () => {
  let deleted: string | null = null;
  mock.onDelete('/me/places/clara/tags/1').reply(() => {
    deleted = '1';
    return [200, { data: [] }];
  });

  render(<MyTags slug="clara" tags={[tag('1', 'visitar a las 5')]} />, { wrapper: Providers });

  fireEvent.press(screen.getByLabelText('Remove visitar a las 5'));
  await waitFor(() => expect(deleted).toBe('1'));
});
