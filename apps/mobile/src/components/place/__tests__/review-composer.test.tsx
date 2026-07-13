import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import type { AppReview } from '@/api/places';
import { ReviewComposer } from '@/components/place/review-composer';
import { useSessionStore } from '@/stores/session';

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
  useSessionStore.setState({ status: 'guest', user: null });
});

it('prompts guests to sign in and does not show the form', () => {
  useSessionStore.setState({ status: 'guest', user: null });
  render(<ReviewComposer placeId="9" slug="clara-cafe" own={null} />, { wrapper: Providers });

  expect(screen.getByText('Sign in to leave a review.')).toBeOnTheScreen();
  expect(screen.queryByText('Rate this place')).toBeNull();
});

it('lets an authed user pick a rating and PUT a new review', async () => {
  useSessionStore.setState({ status: 'authed' });
  let sent: Record<string, unknown> = {};
  mock.onPut('/places/9/reviews').reply((cfg) => {
    sent = JSON.parse(cfg.data);
    return [200, { data: { id: '1', rating: 5, body: 'Great', author: null, is_own: true, created_at: null } }];
  });

  render(<ReviewComposer placeId="9" slug="clara-cafe" own={null} />, { wrapper: Providers });

  expect(screen.getByText('Rate this place')).toBeOnTheScreen();
  fireEvent.press(screen.getByLabelText('5'));
  fireEvent.changeText(screen.getByPlaceholderText('Share a quick thought…'), 'Great');
  fireEvent.press(screen.getByRole('button', { name: 'Post' }));

  await waitFor(() => expect(sent).toEqual({ rating: 5, body: 'Great' }));
});

it('prefills an existing review and shows Update + Delete', () => {
  useSessionStore.setState({ status: 'authed' });
  const own: AppReview = { id: '3', rating: 4, body: 'Solid', author: null, is_own: true, created_at: null };

  render(<ReviewComposer placeId="9" slug="clara-cafe" own={own} />, { wrapper: Providers });

  expect(screen.getByText('Your review')).toBeOnTheScreen();
  expect(screen.getByDisplayValue('Solid')).toBeOnTheScreen();
  expect(screen.getByRole('button', { name: 'Update' })).toBeOnTheScreen();
  expect(screen.getByRole('button', { name: 'Delete' })).toBeOnTheScreen();
});
