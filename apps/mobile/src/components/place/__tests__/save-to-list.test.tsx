import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import { SaveToListSheet } from '@/components/place/save-to-list';

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

it('lists the user lists with a contains flag and toggles membership', async () => {
  mock.onGet('/me/lists').reply(200, {
    data: [
      { id: '1', name: 'Portugal', slug: 'portugal', is_public: false, items_count: 2, contains: false, created_at: null, updated_at: null },
      { id: '2', name: 'Favorites', slug: 'favorites', is_public: false, items_count: 5, contains: true, created_at: null, updated_at: null },
    ],
  });
  let added: string | null = null;
  mock.onPost(/\/me\/lists\/1\/places\/9/).reply(() => {
    added = '1';
    return [201, { data: {} }];
  });

  render(<SaveToListSheet placeId="9" visible onClose={() => {}} />, { wrapper: Providers });

  expect(await screen.findByText('Portugal')).toBeOnTheScreen();
  expect(screen.getByText('Favorites')).toBeOnTheScreen();

  // 'Portugal' is not yet a member → tapping adds.
  fireEvent.press(screen.getByLabelText('Portugal'));
  await waitFor(() => expect(added).toBe('1'));
});

it('creates a new list and adds the place to it', async () => {
  mock.onGet('/me/lists').reply(200, { data: [] });
  let createdName: string | null = null;
  mock.onPost('/me/lists').reply((cfg) => {
    createdName = JSON.parse(cfg.data).name;
    return [201, { data: { id: '7', name: createdName, slug: 'trip', is_public: false, items_count: 0, created_at: null, updated_at: null } }];
  });
  let addedToList: string | null = null;
  mock.onPost(/\/me\/lists\/7\/places\/9/).reply(() => {
    addedToList = '7';
    return [201, { data: {} }];
  });

  render(<SaveToListSheet placeId="9" visible onClose={() => {}} />, { wrapper: Providers });

  fireEvent.press(await screen.findByLabelText('New list'));
  fireEvent.changeText(screen.getByPlaceholderText('List name (e.g. Portugal trip)'), 'Trip');
  fireEvent.press(screen.getByLabelText('Create'));

  await waitFor(() => expect(createdName).toBe('Trip'));
  await waitFor(() => expect(addedToList).toBe('7'));
});
