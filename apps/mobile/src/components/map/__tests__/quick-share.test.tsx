import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import type { SharePlace } from '@/api/shares';
import { QuickShareModal } from '@/components/map/quick-share';

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

it('submits a pasted link and hands back the published place', async () => {
  const place: SharePlace = { id: 'p1', name: 'Time Out Market', lat: 38.7, lng: -9.1 };
  let sentBody: Record<string, unknown> | null = null;
  mock.onPost('/shares').reply((cfg) => {
    sentBody = JSON.parse(cfg.data);
    return [202, { data: { id: 's1', status: 'pending' } }];
  });
  mock.onGet('/shares/s1').reply(200, {
    data: { id: 's1', status: 'published', status_history: [], source_post: {}, analysis: null, failure: null, place },
  });

  const onPublished = jest.fn();
  const onClose = jest.fn();
  render(<QuickShareModal visible onClose={onClose} onPublished={onPublished} />, { wrapper: Providers });

  fireEvent.changeText(screen.getByPlaceholderText(/Paste a link/), 'https://instagram.com/reel/abc');
  fireEvent.press(screen.getByLabelText('Add'));

  // A URL-looking input is sent as `url`, not `caption`.
  await waitFor(() => expect(sentBody).toEqual(expect.objectContaining({ url: 'https://instagram.com/reel/abc' })));
  // On publish, the place is handed back and the popup closes.
  await waitFor(() => expect(onPublished).toHaveBeenCalledWith(place));
  await waitFor(() => expect(onClose).toHaveBeenCalled());
});

it('sends free text as a caption, not a url', async () => {
  let sentBody: Record<string, unknown> | null = null;
  mock.onPost('/shares').reply((cfg) => {
    sentBody = JSON.parse(cfg.data);
    return [202, { data: { id: 's2', status: 'pending' } }];
  });
  mock.onGet('/shares/s2').reply(200, {
    data: { id: 's2', status: 'review', status_history: [], source_post: {}, analysis: null, failure: null, place: null },
  });

  const onPublished = jest.fn();
  render(<QuickShareModal visible onClose={() => {}} onPublished={onPublished} />, { wrapper: Providers });

  fireEvent.changeText(screen.getByPlaceholderText(/Paste a link/), 'Best tacos in Lisbon');
  fireEvent.press(screen.getByLabelText('Add'));

  await waitFor(() => expect(sentBody).toEqual(expect.objectContaining({ caption: 'Best tacos in Lisbon' })));
  // A non-published terminal state surfaces a message + retry instead of closing.
  expect(await screen.findByText('Share another')).toBeOnTheScreen();
  expect(onPublished).not.toHaveBeenCalled();

  // "Share another" drops the mutation and returns to the empty input.
  fireEvent.press(screen.getByText('Share another'));
  expect(await screen.findByPlaceholderText(/Paste a link/)).toBeOnTheScreen();
});
