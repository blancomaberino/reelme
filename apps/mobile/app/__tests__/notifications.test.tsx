import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import NotificationsScreen from '../notifications';
import { api } from '@/api/client';
import type { NotificationRow } from '@/api/notifications';
import { useSettingsStore } from '@/stores/settings';

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

it('renders a notification from its type and params, not the stored strings', async () => {
  // The server's own `title`/`body` are deliberately nonsense here: the row is
  // rendered from `type` + `data`, so the screen must ignore them entirely for
  // a type it knows. That is what lets a row written in Spanish months ago read
  // in English once the user flips the language toggle.
  mock.onGet('/notifications').reply(
    200,
    page([row({ title: 'STALE', body: 'STALE', data: { place_name: 'Bar Tinta' } })]),
  );

  render(<NotificationsScreen />, { wrapper: Providers });

  expect(await screen.findByText('Place added!')).toBeTruthy();
  expect(screen.getByText('Bar Tinta is on your map now.')).toBeTruthy();
  expect(screen.queryByText('STALE')).toBeNull();
});

it('follows the language toggle', async () => {
  mock.onGet('/notifications').reply(200, page([row({ data: { place_name: 'Bar Tinta' } })]));
  useSettingsStore.setState({ locale: 'es' });

  render(<NotificationsScreen />, { wrapper: Providers });

  expect(await screen.findByText('¡Lugar añadido!')).toBeTruthy();
  expect(screen.getByText('Bar Tinta ya está en tu mapa.')).toBeTruthy();
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

describe('per-row mark as read', () => {
  it('clears the row without opening it', async () => {
    // The distinction that matters: opening a notification marks it read as a
    // side effect, but the dot must be able to clear it WITHOUT navigating —
    // otherwise dismissing a badge costs you a trip into a screen you did not
    // want.
    mock.onGet('/notifications').reply(200, page([row({ url: '/place/bar-tinta' })]));
    mock.onPost('/notifications/read').reply(200, { data: { unread_count: 0 } });

    render(<NotificationsScreen />, { wrapper: Providers });
    fireEvent.press(await screen.findByTestId('mark-read-n1'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data)).toEqual({ ids: ['n1'] });
    expect(mockRouter.push).not.toHaveBeenCalled();
  });

  it('drops the dot optimistically, before the request resolves', async () => {
    mock.onGet('/notifications').reply(200, page([row()]));
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
    expect(await screen.findByTestId('unread-dot')).toBeTruthy();

    fireEvent.press(screen.getByTestId('mark-read-n1'));

    // The POST landing in history is the exact moment "in flight" begins, and
    // the reply above is still held, so this assertion is by definition
    // pre-response.
    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(screen.queryByTestId('unread-dot')).toBeNull();

    release();
  });

  it('leaves the row where it is instead of re-sorting it into Earlier', async () => {
    /*
     * The reported bug. Sectioning used to split on live `read_at`, so clearing
     * a row moved it under "Earlier" that same frame and every row below it
     * jumped up by a row height — under the thumb that had just tapped. It
     * reads as the list scrolling itself.
     *
     * Both rows start unread, so a correct implementation shows no "Earlier"
     * section at all after one is cleared.
     */
    mock.onGet('/notifications').reply(200, page([row({ id: 'a' }), row({ id: 'b' })]));
    mock.onPost('/notifications/read').reply(200, { data: { unread_count: 1 } });

    render(<NotificationsScreen />, { wrapper: Providers });
    expect(await screen.findByText('New')).toBeTruthy();
    expect(screen.queryByText('Earlier')).toBeNull();

    fireEvent.press(screen.getByTestId('mark-read-a'));

    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(screen.queryByText('Earlier')).toBeNull();
    // Still present, still in the same section — cleared, not relocated.
    expect(screen.getByTestId('notification-a')).toBeTruthy();
  });

  it('offers no mark-read control on a row that is already read', async () => {
    // An action that silently does nothing is worse than no action.
    mock.onGet('/notifications').reply(200, page([row({ read_at: '2026-07-30T09:00:00Z' })]));

    render(<NotificationsScreen />, { wrapper: Providers });
    await screen.findByTestId('notification-n1');

    expect(screen.queryByTestId('mark-read-n1')).toBeNull();
  });
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

    // Sequence on the request being issued rather than on a wall-clock timeout:
    // the POST appearing in history is the exact moment "in flight" begins, and
    // the reply above is still held open, so anything asserted after this line
    // is by definition pre-response.
    await waitFor(() => expect(mock.history.post).toHaveLength(1));
    expect(JSON.parse(mock.history.post[0].data)).toEqual({ all: true });

    // Still in flight, and the dots are already gone.
    expect(screen.queryAllByTestId('unread-dot')).toHaveLength(0);

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

/**
 * The bug this screen actually shipped with.
 *
 * Rows written before the server stored `title`/`body` at all carry only
 * `{type, url, share_id}`, and the fallback was `item.type` — so twenty legacy
 * rows rendered as a column reading "share.published", with no body, inside an
 * otherwise Spanish app. A machine string is never a sentence.
 */
it('renders legacy rows that carry no copy at all', async () => {
  mock.onGet('/notifications').reply(200, page([row({ title: null, body: null, data: {} })]));

  render(<NotificationsScreen />, { wrapper: Providers });

  // The type still resolves the copy; the missing `place_name` only picks the
  // un-named variant of the body.
  expect(await screen.findByText('Place added!')).toBeTruthy();
  expect(screen.getByText('Your place is on the map now.')).toBeTruthy();
  expect(screen.queryByText('share.published')).toBeNull();
});

it('never shows a raw machine string, even for an unknown type with no copy', async () => {
  mock.onGet('/notifications').reply(
    200,
    page([row({ type: 'something.invented.later', title: null, body: null, data: {} })]),
  );

  render(<NotificationsScreen />, { wrapper: Providers });

  expect(await screen.findByText('Update')).toBeTruthy();
  expect(screen.queryByText('something.invented.later')).toBeNull();
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
