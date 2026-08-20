import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Platform } from 'react-native';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import ShareScreen from '../share';
import { api } from '@/api/client';
import type { ShareDetail } from '@/api/shares';
import { useUiStore } from '@/stores/ui';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function shareDetail(over: Partial<ShareDetail>): ShareDetail {
  return {
    id: '1',
    status: 'pending',
    status_history: [],
    source_post: {
      id: '1',
      platform: 'instagram',
      url: 'https://ig.com/reel/x',
      author_handle: null,
      caption: null,
      fetch_status: 'ok',
    },
    analysis: null,
    failure: null,
    can_publish_best_guess: false,
    place: null,
    places: [],
    pending_place_count: 0,
    pending_places: [],
    ...over,
  };
}

/**
 * Capture the body of `POST /shares`. `.body` stays null until a request is
 * actually made — which is the assertion half the T-137 tests turn on.
 */
function capturePostShares(): { body: Record<string, unknown> | null } {
  const captured: { body: Record<string, unknown> | null } = { body: null };
  mock.onPost('/shares').reply((cfg) => {
    captured.body = JSON.parse(cfg.data);
    return [201, { data: shareDetail({ id: '1', status: 'pending' }) }];
  });
  return captured;
}

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.push.mockClear();
  mockRouter.replace.mockClear();
  mockRouter.params = {};
  // No share staged in the UI store unless a test opts in (auth-gate resume).
  useUiStore.setState({ pendingShare: null });
  // Recent-shares list: default to empty so the section stays hidden unless a
  // test opts in.
  mock.onGet('/shares').reply(200, { data: [] });
});
afterEach(() => {
  mock.restore();
  qc.clear();
});

it('submits a link, shows the published pin, and navigates to the place', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'published',
      place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 },
    }),
  });

  render(<ShareScreen />, { wrapper: Providers });

  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(screen.getByText('Clara Café')).toBeOnTheScreen();

  // T-076: a single clean publish auto-opens the place detail (no manual tap).
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '9' } });
});

it('fetches the real state when POST replays an already-published share (no false "failed")', async () => {
  // Idempotent replay: the 202 ack is terminal + stripped (place null). The
  // screen must still GET the full resource and render the published place,
  // not misread the stripped payload as a failure.
  mock.onPost('/shares').reply(202, { data: { id: '1', status: 'published' } });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(screen.getByText('Clara Café')).toBeOnTheScreen();
});

it('offers "Publish anyway" for an uncertain review and skips to the status screen (T-098)', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'review', can_publish_best_guess: true }),
  });
  mock.onPost('/shares/1/publish-best-guess').reply(200, {
    data: shareDetail({ id: '1', status: 'analyzing' }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  fireEvent.press(await screen.findByText('Publish anyway'));

  await waitFor(() => expect(mock.history.post.some((r) => r.url === '/shares/1/publish-best-guess')).toBe(true));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/shares/[id]/status', params: { id: '1' } });
});

it('does not offer "Publish anyway" for a review that needs a location (can_publish_best_guess false)', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'review',
      can_publish_best_guess: false,
      failure: { code: 'geocode_failed', step: 'resolve', message: 'Couldn’t place it', manual_fallback: true },
    }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  // The LOCALIZED copy for the code, not the server's `message`. That string
  // is written in English by the API, which has no idea what locale the device
  // is in — it was rendering an English sentence inside a Spanish screen, and
  // this assertion is what used to pin the bug in place.
  await screen.findByText('Check the address or drop the pin yourself.');
  expect(screen.queryByText('Publish anyway')).toBeNull();
  expect(screen.queryByText('Couldn’t place it')).toBeNull();
});

it('PREFILLS a deep-link payload and waits for a tap — it never auto-submits (T-137)', async () => {
  // `reelmap://share?sharedUrl=…` is reachable by any other installed app or web
  // page, so the route-param path must not be able to spend a share on its own.
  // Reproduced against the running app on 2026-08-20: it did.
  mockRouter.params = { sharedUrl: 'https://instagram.com/reel/abc' };
  const post = capturePostShares();
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });

  // Prefilled — the CI/Maestro fallback this path exists for still works…
  expect(await screen.findByDisplayValue('https://instagram.com/reel/abc')).toBeOnTheScreen();
  // …but nothing was sent, and the form is still the form.
  await waitFor(() => expect(screen.getByRole('button', { name: 'Pin it' })).toBeOnTheScreen());
  expect(post.body).toBeNull();

  // Only the tap submits it.
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));
  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  // A deep-link prefill followed by a real tap is a paste, not a share-sheet
  // ingest — pinned so a refactor cannot quietly restore the old attribution.
  expect(post.body).toMatchObject({ url: 'https://instagram.com/reel/abc', shared_via: 'paste_url' });
});

it('does NOT auto-submit a staged payload on Android, where the intent is forgeable (T-137)', async () => {
  // `app.config.ts` registers `androidIntentFilters: ['text/*']` on the exported
  // MainActivity, so any installed app can startActivity an EXPLICIT ACTION_SEND
  // with setPackage(us) — no chooser, no tap — and land in the same store the
  // iOS share extension writes. iOS's payload comes from the app group, which no
  // other app can write; Android's does not, so Android prefills and waits.
  const os = Object.getOwnPropertyDescriptor(Platform, 'OS');
  Object.defineProperty(Platform, 'OS', { value: 'android', configurable: true });
  try {
    useUiStore.setState({ pendingShare: { url: 'https://instagram.com/reel/abc', text: '' } });
  const post = capturePostShares();

    render(<ShareScreen />, { wrapper: Providers });

    // Prefilled and waiting for the button — not sent.
    expect(await screen.findByDisplayValue('https://instagram.com/reel/abc')).toBeOnTheScreen();
    expect(screen.getByRole('button', { name: 'Pin it' })).toBeOnTheScreen();
    expect(post.body).toBeNull();
    // Still consumed exactly once, so it cannot re-fire on the next render.
    expect(useUiStore.getState().pendingShare).toBeNull();

    // The tap still works — Android loses the auto-submit, not the feature.
    fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));
    await waitFor(() => expect(post.body).toMatchObject({ url: 'https://instagram.com/reel/abc' }));
  } finally {
    if (os) Object.defineProperty(Platform, 'OS', os);
  }
});

it('an untrusted staged payload cannot swap out a link you typed either (T-137)', async () => {
  // The Android counterpart of the deep-link bait-and-switch: an explicit
  // ACTION_SEND foregrounds the app on the composer with someone else's URL in
  // the field, and a habitual tap submits it. On iOS the staged payload IS the
  // tap the person just made, so there it still wins — asserted below.
  const os = Object.getOwnPropertyDescriptor(Platform, 'OS');
  Object.defineProperty(Platform, 'OS', { value: 'android', configurable: true });
  try {
    const post = capturePostShares();
    render(<ShareScreen />, { wrapper: Providers });
    fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/mine');

    act(() => {
      useUiStore.setState({ pendingShare: { url: 'https://attacker.example/x', text: '' } });
    });

    await waitFor(() => expect(screen.getByDisplayValue('https://ig.com/reel/mine')).toBeOnTheScreen());
    expect(screen.queryByDisplayValue('https://attacker.example/x')).toBeNull();
    expect(post.body).toBeNull();
  } finally {
    if (os) Object.defineProperty(Platform, 'OS', os);
  }
});

it('a share-sheet payload DOES replace what you typed on iOS (T-137)', async () => {
  // The other half: on iOS the payload comes from the app group and means the
  // person just chose "share to Reelmap" — refusing it there would be wrong.
  const post = capturePostShares();
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/mine');

  act(() => {
    useUiStore.setState({ pendingShare: { url: 'https://instagram.com/reel/fromsheet', text: '' } });
  });

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(post.body).toMatchObject({ url: 'https://instagram.com/reel/fromsheet', shared_via: 'share_sheet' });
});

it('does not auto-submit a payload arriving as sharedText either (T-137)', async () => {
  // `sharedText` is a param NOTHING in the app ever sets — only an external
  // `reelmap://share?sharedText=…` produces one, so it gets the same treatment
  // as `sharedUrl`. Without this, a future edit could re-add the auto-submit on
  // this half of the pair and every other test would stay green.
  mockRouter.params = { sharedText: 'https://instagram.com/reel/fromparam' };
  const post = capturePostShares();

  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByDisplayValue('https://instagram.com/reel/fromparam')).toBeOnTheScreen();
  expect(screen.getByRole('button', { name: 'Pin it' })).toBeOnTheScreen();
  expect(post.body).toBeNull();
});

it('refuses a non-http(s) URL TYPED into the composer, before any request (T-137)', async () => {
  // The scheme gate lives in doSubmit, not only in the prefill helper: the URL
  // field is free text, and the API accepted `ftp://` (verified 202) until this
  // change added its own allowlist.
  const post = capturePostShares();

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'ftp://example.com/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('That link needs to start with http:// or https://.')).toBeOnTheScreen();
  expect(post.body).toBeNull();

  // …and the same field with a real link goes through, so the guard is proven
  // both ways rather than just proven to refuse.
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));
  await waitFor(() => expect(post.body).toMatchObject({ url: 'https://ig.com/reel/x' }));
});

it('a dropped deep-link payload does not WIPE the form it lands on (T-137)', async () => {
  // Isolates the `if (!u && !cap) return` guard specifically. Typing first would
  // NOT isolate it — the `edited` guard fires earlier and the test would stay
  // green with the guard removed. So: prefill from a valid param, type nothing,
  // then let a second, dropped payload arrive.
  mockRouter.params = { sharedUrl: 'https://instagram.com/reel/first' };
  const post = capturePostShares();

  render(<ShareScreen />, { wrapper: Providers });
  expect(await screen.findByDisplayValue('https://instagram.com/reel/first')).toBeOnTheScreen();

  mockRouter.params = { sharedUrl: 'ftp://example.com/x' };
  screen.rerender(<ShareScreen />);

  // The foreign scheme is dropped — and dropping it leaves the field alone
  // rather than clearing it.
  await waitFor(() => expect(screen.getByDisplayValue('https://instagram.com/reel/first')).toBeOnTheScreen());
  expect(post.body).toBeNull();
});

it('a deep link cannot swap out a link you typed yourself (T-137)', async () => {
  // Bait-and-switch: the app foregrounds on the composer with someone else's
  // URL in the field and a habitual tap submits it. A prefill fills an
  // untouched form only.
  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/mine');

  // A plain assignment — no React update happens here; the rerender below is
  // what re-renders (and RTL already wraps that in act).
  mockRouter.params = { sharedUrl: 'https://attacker.example/x' };
  screen.rerender(<ShareScreen />);

  await waitFor(() => expect(screen.getByDisplayValue('https://ig.com/reel/mine')).toBeOnTheScreen());
  expect(screen.queryByDisplayValue('https://attacker.example/x')).toBeNull();
});

it('drops a non-http(s) deep-link URL instead of forwarding it (T-137)', async () => {
  // The scheme was checked on `sharedText` and not on `sharedUrl`, so an
  // attacker-chosen value went to the API verbatim: `ftp://example.com/…` was
  // accepted end to end on 2026-08-20 (the API's `url` rule is filter_var, not
  // an http(s) allowlist — it refuses `javascript:` by luck, not by design).
  mockRouter.params = { sharedUrl: 'javascript:alert(1)' };
  const post = capturePostShares();

  render(<ShareScreen />, { wrapper: Providers });

  // Not prefilled, so there is nothing for a tap to send either.
  expect(await screen.findByRole('button', { name: 'Pin it' })).toBeOnTheScreen();
  expect(screen.queryByDisplayValue('javascript:alert(1)')).toBeNull();
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));
  expect(await screen.findByText('Paste a link or a caption first.')).toBeOnTheScreen();
  expect(post.body).toBeNull();
});

it('drops a non-http(s) URL staged by the share extension too (T-137)', async () => {
  // One check, applied to whichever source the value came from — the staged
  // path auto-submits, so it is the one that must not carry a foreign scheme.
  useUiStore.setState({ pendingShare: { url: 'ftp://example.com/x', text: '' } });
  const post = capturePostShares();

  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByText('Paste a link or a caption first.')).toBeOnTheScreen();
  expect(post.body).toBeNull();
});

it('survives a REPEATED deep-link param instead of crashing on it (T-137)', async () => {
  // `?sharedUrl=a&sharedUrl=b` — free for anyone to compose — makes
  // useLocalSearchParams hand back an ARRAY, and `.trim()` on an array is a
  // TypeError that takes the screen down. Verified against the code before this
  // guard: "(rawUrl ?? '').trim is not a function".
  mockRouter.params = { sharedUrl: ['https://a.example/1', 'https://b.example/2'] } as never;
  const post = capturePostShares();

  render(<ShareScreen />, { wrapper: Providers });

  // The screen is alive, the first value prefilled, and still nothing sent.
  expect(await screen.findByDisplayValue('https://a.example/1')).toBeOnTheScreen();
  expect(screen.getByRole('button', { name: 'Pin it' })).toBeOnTheScreen();
  expect(post.body).toBeNull();
});

it('takes a URL out of shared TEXT, and non-URL text as the caption (T-137)', async () => {
  // The share extension often hands over text only ("look at this <link>"),
  // which is the arm that decides url-vs-caption.
  useUiStore.setState({ pendingShare: { url: '', text: 'https://www.instagram.com/reel/fromtext/' } });
  const post = capturePostShares();
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(post.body).toMatchObject({ url: 'https://www.instagram.com/reel/fromtext/', shared_via: 'share_sheet' });
  expect(post.body).not.toHaveProperty('caption');
});

it('sends non-URL shared text as the caption, not as a URL (T-137)', async () => {
  useUiStore.setState({ pendingShare: { url: '', text: 'Bar Tabaré, Montevideo' } });
  const post = capturePostShares();
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(post.body).toMatchObject({ caption: 'Bar Tabaré, Montevideo', shared_via: 'share_sheet' });
  expect(post.body).not.toHaveProperty('url');
});

it('does not overwrite what you typed after a deep-link prefill (T-137)', async () => {
  // The prefill effect must not re-run on every rebuilt callback: the params
  // stay in the route for as long as the screen is mounted.
  mockRouter.params = { sharedUrl: 'https://instagram.com/reel/abc' };
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });

  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByDisplayValue('https://instagram.com/reel/abc')).toBeOnTheScreen();
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/mine');
  // A re-render (any state change: typing in the caption field) must leave it.
  fireEvent.changeText(screen.getByLabelText('…or a caption'), 'my note');
  await waitFor(() => expect(screen.getByDisplayValue('https://ig.com/reel/mine')).toBeOnTheScreen());
});

it('lets you share the SAME post again after "share another" (T-137 / MOB-8)', async () => {
  // The dedupe was keyed on the payload string and nothing ever reset it, so
  // re-sharing an already-shared post foregrounded onto an empty form and made
  // no request — leaving the "already added" note unreachable. Keyed on the
  // staged object, a second staging is a second submit.
  const posted: Record<string, unknown>[] = [];
  mock.onPost('/shares').reply((cfg) => {
    posted.push(JSON.parse(cfg.data));
    return posted.length === 1
      ? [201, { data: shareDetail({ id: '1', status: 'pending' }) }]
      : [202, { data: { id: '1', status: 'published' }, meta: { idempotent_replay: true } }];
  });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  useUiStore.setState({ pendingShare: { url: 'https://instagram.com/reel/abc', text: '' } });
  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(posted).toHaveLength(1);

  // "Share another" → back to the form.
  fireEvent.press(screen.getByText('Share another'));
  expect(await screen.findByRole('button', { name: 'Pin it' })).toBeOnTheScreen();

  // The same post, shared in again: a NEW staged object, so it submits again…
  act(() => {
    useUiStore.setState({ pendingShare: { url: 'https://instagram.com/reel/abc', text: '' } });
  });

  // …and the replay note — previously unreachable — is what the user sees.
  expect(await screen.findByText('You already added this one.')).toBeOnTheScreen();
  expect(posted).toHaveLength(2);
  expect(posted[1]).toMatchObject({ url: 'https://instagram.com/reel/abc', shared_via: 'share_sheet' });
});

it('resumes a share staged in the UI store (unauthenticated share surviving login)', async () => {
  // The root ShareIntentRedirect stages the payload before the sign-in redirect
  // so it isn't lost to the auth gate; post-login the ingest screen consumes it.
  useUiStore.setState({ pendingShare: { url: 'https://tiktok.com/@a/video/1', text: '' } });
  const post = capturePostShares();
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByText('Pinned!')).toBeOnTheScreen();
  expect(post.body).toMatchObject({ url: 'https://tiktok.com/@a/video/1', shared_via: 'share_sheet' });
  // The staged share is consumed exactly once (not left to re-fire on resume).
  expect(useUiStore.getState().pendingShare).toBeNull();
});

it('shows a platform badge for a recognized pasted link', async () => {
  render(<ShareScreen />, { wrapper: Providers });

  // Nothing typed → no badge.
  expect(screen.queryByText('Instagram')).not.toBeOnTheScreen();

  fireEvent.changeText(screen.getByLabelText('Link'), 'https://www.instagram.com/reel/x/');
  expect(await screen.findByText('Instagram')).toBeOnTheScreen();

  // A different host re-derives the badge; an unrecognized one hides it.
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://youtu.be/abc');
  expect(await screen.findByText('YouTube')).toBeOnTheScreen();
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://example.com/x');
  expect(screen.queryByText('YouTube')).not.toBeOnTheScreen();
});

it('notes a re-shared post as already added (idempotent replay), not an error', async () => {
  mock.onPost('/shares').reply(202, { data: { id: '1', status: 'published' }, meta: { idempotent_replay: true } });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({ id: '1', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('You already added this one.')).toBeOnTheScreen();
  expect(screen.getByText('Clara Café')).toBeOnTheScreen();
});

it('offers Retry on a failed share and re-runs the pipeline', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'failed',
      failure: { code: 'ollama_unreachable', step: 'analyze', message: 'Analysis failed. Please try again.', manual_fallback: false },
    }),
  });
  mock.onPost('/shares/1/retry').reply(202, {});

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('We couldn’t reach the analysis model. Try again, or switch models.')).toBeOnTheScreen();
  fireEvent.press(screen.getByRole('button', { name: 'Try again' }));

  await waitFor(() => expect(mock.history.post.some((r) => r.url === '/shares/1/retry')).toBe(true));
});

it('lists recent shares with a status pill and taps a published one through', async () => {
  mock.onGet('/shares').reply(200, {
    data: [
      shareDetail({ id: '5', status: 'analyzing', source_post: { ...shareDetail({}).source_post, caption: 'ramen spot' } }),
      shareDetail({ id: '6', status: 'published', place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 } }),
    ],
  });

  render(<ShareScreen />, { wrapper: Providers });

  expect(await screen.findByText('Recent shares')).toBeOnTheScreen();
  expect(screen.getByText('Analyzing')).toBeOnTheScreen();
  expect(screen.getByText('Published')).toBeOnTheScreen();

  fireEvent.press(screen.getByText('Clara Café'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '9' } });
});

it('shows a validation error and does not POST when both inputs are empty', async () => {
  render(<ShareScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('Paste a link or a caption first.')).toBeOnTheScreen();
  expect(mock.history.post).toHaveLength(0);
});

it('surfaces a review outcome in the user’s language, not the server’s', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'review',
      failure: { code: 'geocode_failed', step: 'resolve', message: "We couldn't pin this place.", manual_fallback: true },
    }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  expect(await screen.findByText('Needs a quick review')).toBeOnTheScreen();
  expect(screen.getByText('Check the address or drop the pin yourself.')).toBeOnTheScreen();
  // The server's own sentence never reaches the screen — it is for logs.
  expect(screen.queryByText("We couldn't pin this place.")).toBeNull();
});

it('does NOT auto-open a detail for a multi-place publish — it lists them to tap through (T-076)', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'published',
      places: [
        { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 },
        { id: '10', name: 'Bar Tabaré', lat: -34.9, lng: -56.2 },
      ],
    }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  // Both venues are listed and none is auto-opened (no venue lost).
  expect(await screen.findByText('Clara Café')).toBeOnTheScreen();
  expect(screen.getByText('Bar Tabaré')).toBeOnTheScreen();
  expect(mockRouter.push).not.toHaveBeenCalled();

  // Tapping one still routes through to its detail.
  fireEvent.press(screen.getByText('Bar Tabaré'));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '10' } });
});

it('does NOT auto-open when a single publish still has venues in review (T-076)', async () => {
  mock.onPost('/shares').reply(201, { data: shareDetail({ id: '1', status: 'pending' }) });
  mock.onGet('/shares/1').reply(200, {
    data: shareDetail({
      id: '1',
      status: 'published',
      place: { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 },
      pending_place_count: 1,
      pending_places: [{ index: 1, name: 'Mystery Spot', reason: 'geocode_failed', candidates: [] }],
    }),
  });

  render(<ShareScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByLabelText('Link'), 'https://ig.com/reel/x');
  fireEvent.press(screen.getByRole('button', { name: 'Pin it' }));

  // The card stays so the pending venue can be resolved — no abrupt jump.
  expect(await screen.findByText('Clara Café')).toBeOnTheScreen();
  expect(mockRouter.push).not.toHaveBeenCalled();

  // The explicit "View place" button is still available to open it.
  fireEvent.press(screen.getByRole('button', { name: 'View place' }));
  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '9' } });
});
