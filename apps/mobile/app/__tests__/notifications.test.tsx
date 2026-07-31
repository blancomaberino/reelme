import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import NotificationsScreen from '../notifications';
import { api } from '@/api/client';
import type { NotificationRow } from '@/api/notifications';

import { mockRouter } from '../../jest.setup';

/**
 * The notification center (T-040).
 *
 * A push is an interruption; this is the record. The rules worth pinning are
 * the ones that decide whether a notification is FINDABLE and ACTIONABLE:
 * unread/read sectioning, deep-linking through `data.url` (the same contract
 * the push tap handler uses), and forward-compatibility with types M4 has not
 * shipped yet.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

function row(over: Partial<NotificationRow> = {}): NotificationRow {
  return {
    id: 'n1',
    type: 'share.published',
    title: 'Published',
    body: 'Bar Tinta is on your map.',
    url: '/place/bar-tinta',
    data: {},
    read_at: null,
    created_at: '2026-07-31T10:00:00Z',
    ...over,
  };
}

function page(rows: NotificationRow[], unread = rows.filter((r) => r.read_at === null).length, next: string | null = null) {
  return {
    data: rows,
    meta: { unread_count: unread, pagination: { next_cursor: next, prev_cursor: null, limit: 25 } },
  };
}

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('renders a notification with its title and body', async () => {
  mock.onGet('/notifications').reply(200, page([row()]));

  render(<NotificationsScreen />, { wrapper: Providers });

  expect(await screen.findByText('Published')).toBeTruthy();
  expect(screen.getByText('Bar Tinta is on your map.')).toBeTruthy();
});

it('separates unread from already-seen', async () => {
  mock.onGet('/notifications').reply(
    200,
    page([row({ id: 'a' }), row({ id: 'b', read_at: '2026-07-30T09:00:00Z' })]),
  );

  render(<NotificationsScreen />, { wrapper: Providers });

  expect(await screen.findByText('New')).toBeTruthy();
  expect(screen.getByText('Earlier')).toBeTruthy();
  // Only the unread row carries the dot.
  expect(screen.getAllByTestId('unread-dot')).toHaveLength(1);
});

it('deep-links through data.url — the same contract as a push tap', async () => {
  mock.onGet('/notifications').reply(200, page([row({ url: '/shares/12/review' })]));
  mock.onPost('/notifications/read').reply(200, { data: { unread_count: 0 } });

  render(<NotificationsScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByTestId('notification-n1'));

  expect(mockRouter.push).toHaveBeenCalledWith('/shares/12/review');
});

it('marks a row read when it is opened', async () => {
  mock.onGet('/notifications').reply(200, page([row()]));
  mock.onPost('/notifications/read').reply(200, { data: { unread_count: 0 } });

  render(<NotificationsScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByTestId('notification-n1'));

  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  expect(JSON.parse(mock.history.post[0].data)).toEqual({ ids: ['n1'] });
});

it('does not re-mark a row that was already read', async () => {
  mock.onGet('/notifications').reply(200, page([row({ read_at: '2026-07-30T09:00:00Z' })]));

  render(<NotificationsScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByTestId('notification-n1'));

  expect(mockRouter.push).toHaveBeenCalled();
  expect(mock.history.post).toHaveLength(0);
});

describe('mark all read', () => {
  it('posts {all: true} and clears the unread styling BEFORE the request resolves', async () => {
    mock.onGet('/notifications').reply(200, page([row({ id: 'a' }), row({ id: 'b' })]));
    // Hold the response open so "optimistic" is actually observable — with an
    // instant reply this would pass even for a non-optimistic implementation.
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    mock.onPost('/notifications/read').reply(async () => {
      await held;
      return [200, { data: { unread_count: 0 } }];
    });

    render(<NotificationsScreen />, { wrapper: Providers });
    fireEvent.press(await screen.findByTestId('mark-all-read'));

    // Still in flight, and the dots are already gone.
    await waitFor(() => expect(screen.queryAllByTestId('unread-dot')).toHaveLength(0));
    expect(JSON.parse(mock.history.post[0].data)).toEqual({ all: true });

    release();
  });

  it('is hidden when there is nothing unread', async () => {
    mock.onGet('/notifications').reply(200, page([row({ read_at: '2026-07-30T09:00:00Z' })]));

    render(<NotificationsScreen />, { wrapper: Providers });

    await screen.findByText('Earlier');
    expect(screen.queryByTestId('mark-all-read')).toBeNull();
  });

  it('rolls back if the request fails, rather than eating the unread state', async () => {
    mock.onGet('/notifications').reply(200, page([row()]));
    mock.onPost('/notifications/read').reply(500);

    render(<NotificationsScreen />, { wrapper: Providers });
    fireEvent.press(await screen.findByTestId('mark-all-read'));

    // A silently-swallowed failure would leave the user believing they had
    // cleared notifications they had not.
    await waitFor(() => expect(screen.getAllByTestId('unread-dot')).toHaveLength(1));
  });
});

/**
 * Forward-compatibility. M4 emits `redemption.verified` and `wallet.payout`,
 * and a server can add types this build has never heard of — a row must still
 * render from its payload rather than vanishing.
 */
it('renders an unknown type generically instead of dropping it', async () => {
  mock.onGet('/notifications').reply(
    200,
    page([row({ type: 'something.invented.later', title: 'A new thing', body: 'Happened.' })]),
  );

  render(<NotificationsScreen />, { wrapper: Providers });

  expect(await screen.findByText('A new thing')).toBeTruthy();
  expect(screen.getByText('Happened.')).toBeTruthy();
});

it('falls back to the type string when a row has no title', async () => {
  mock.onGet('/notifications').reply(200, page([row({ title: null, body: null })]));

  render(<NotificationsScreen />, { wrapper: Providers });

  expect(await screen.findByText('share.published')).toBeTruthy();
});

it('shows an empty state rather than a blank screen', async () => {
  mock.onGet('/notifications').reply(200, page([]));

  render(<NotificationsScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('notifications-empty')).toBeTruthy();
});

it('offers a retry when the list fails to load', async () => {
  mock.onGet('/notifications').reply(500);

  render(<NotificationsScreen />, { wrapper: Providers });

  expect(await screen.findByTestId('notifications-error')).toBeTruthy();
  expect(screen.getByText('Try again')).toBeTruthy();
});

it('loads the next page when the list reaches its end', async () => {
  mock
    .onGet('/notifications')
    .replyOnce(200, page([row({ id: 'a' })], 2, 'cursor-2'))
    .onGet('/notifications')
    .reply(200, page([row({ id: 'b' })], 2));

  render(<NotificationsScreen />, { wrapper: Providers });
  await screen.findByTestId('notification-a');

  fireEvent.press(screen.getByTestId('flash-list-end'));

  expect(await screen.findByTestId('notification-b')).toBeTruthy();
});
