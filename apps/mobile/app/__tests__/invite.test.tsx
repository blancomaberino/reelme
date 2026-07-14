import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';
import { Alert } from 'react-native';

import InviteScreen from '../invite';
import { api } from '@/api/client';

import { mockRouter } from '../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.back.mockClear();
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('adds valid emails as chips and posts them', async () => {
  let body: unknown = null;
  mock.onPost('/invites').reply((config) => {
    body = JSON.parse(config.data as string);
    return [202, { data: { status: 'queued' } }];
  });
  const alertSpy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

  render(<InviteScreen />, { wrapper: Providers });
  const input = screen.getByPlaceholderText('friend@email.com');

  fireEvent.changeText(input, 'a@example.com');
  fireEvent.press(screen.getByLabelText('Add email'));
  fireEvent.changeText(input, 'b@example.com');
  fireEvent.press(screen.getByLabelText('Add email'));

  expect(screen.getByText('a@example.com')).toBeOnTheScreen();
  fireEvent.press(screen.getByText('Send invites'));

  await waitFor(() => expect(body).toEqual({ emails: ['a@example.com', 'b@example.com'] }));
  await waitFor(() => expect(mockRouter.back).toHaveBeenCalled());
  alertSpy.mockRestore();
});

it('rejects an invalid email instead of adding it', () => {
  render(<InviteScreen />, { wrapper: Providers });
  const input = screen.getByPlaceholderText('friend@email.com');

  fireEvent.changeText(input, 'not-an-email');
  fireEvent.press(screen.getByLabelText('Add email'));

  expect(screen.getByText('Enter a valid email address.')).toBeOnTheScreen();
  expect(screen.queryByText('not-an-email')).toBeNull();
});
