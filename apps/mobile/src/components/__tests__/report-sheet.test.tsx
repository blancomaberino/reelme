import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import { ReportSheet } from '@/components/report-sheet';
import { useSessionStore } from '@/stores/session';

/**
 * The report sheet (T-049).
 *
 * Apple 1.2 and Google's UGC policy require this path to exist and work, so the
 * cases below are the ones that would make it *look* like it works while
 * failing: a submit with no reason, a 409 shown as an error, and state left
 * over from the previous report.
 */
let qc: QueryClient;
let mock: AxiosMockAdapter;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function renderSheet(props: Partial<React.ComponentProps<typeof ReportSheet>> = {}) {
  return render(
    <ReportSheet
      visible
      onClose={props.onClose ?? jest.fn()}
      target={props.target ?? { type: 'place', id: '42' }}
      subject={props.subject ?? 'Bar Tinto'}
    />,
    { wrapper: Providers },
  );
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0 }, queries: { retry: false } } });
  mock = new AxiosMockAdapter(api);
  useSessionStore.setState({ user: null, status: 'authed' });
});

afterEach(() => mock.restore());

it('will not submit until a reason is chosen', () => {
  renderSheet();

  // A report with no reason is unactionable — it fills the queue with rows a
  // moderator cannot triage.
  expect(screen.getByTestId('report-submit').props.accessibilityState.disabled).toBe(true);

  fireEvent.press(screen.getByTestId('report-submit'));
  expect(mock.history.post).toHaveLength(0);

  fireEvent.press(screen.getByTestId('report-reason-spam'));
  expect(screen.getByTestId('report-submit').props.accessibilityState.disabled).toBe(false);
});

it('sends the report and confirms it landed', async () => {
  mock.onPost('/reports').reply(201, { data: { report: { id: '1' } }, meta: {} });

  renderSheet({ target: { type: 'share', id: '7' } });
  fireEvent.press(screen.getByTestId('report-reason-copyright'));
  fireEvent.changeText(screen.getByTestId('report-details'), 'That is my footage.');
  fireEvent.press(screen.getByTestId('report-submit'));

  await waitFor(() => expect(mock.history.post).toHaveLength(1));

  expect(JSON.parse(mock.history.post[0].data)).toEqual({
    reportable_type: 'share',
    reportable_id: '7',
    reason: 'copyright',
    details: 'That is my footage.',
  });
  await screen.findByTestId('report-done');
});

it('treats "already reported" as done, not as a failure', async () => {
  mock.onPost('/reports').reply(409, { data: { report: { id: '1' } }, meta: {} });

  renderSheet();
  fireEvent.press(screen.getByTestId('report-reason-spam'));
  fireEvent.press(screen.getByTestId('report-submit'));

  // A 409 means the flag is already on file — which is what the user wanted.
  // Showing an error would tell them it failed and invite a retry against an
  // endpoint that will keep refusing.
  await screen.findByTestId('report-done');
  expect(screen.queryByTestId('report-error')).toBeNull();
});

it('keeps the form open on a real failure', async () => {
  mock.onPost('/reports').reply(500);

  renderSheet();
  fireEvent.press(screen.getByTestId('report-reason-fraud'));
  fireEvent.changeText(screen.getByTestId('report-details'), 'They keep posting this.');
  fireEvent.press(screen.getByTestId('report-submit'));

  // The chosen reason and any typed details must SURVIVE — asserted, not
  // assumed. Losing them means the person has to compose the whole thing again
  // to retry, which is how a failed report becomes an abandoned one.
  await screen.findByTestId('report-error');
  expect(screen.queryByTestId('report-done')).toBeNull();
  expect(screen.getByTestId('report-details').props.value).toBe('They keep posting this.');
  expect(screen.getByTestId('report-submit').props.accessibilityState.disabled).toBe(false);
});

it('omits empty details rather than sending a blank string', async () => {
  mock.onPost('/reports').reply(201, { data: { report: { id: '1' } }, meta: {} });

  renderSheet();
  fireEvent.press(screen.getByTestId('report-reason-other'));
  fireEvent.changeText(screen.getByTestId('report-details'), '   ');
  fireEvent.press(screen.getByTestId('report-submit'));

  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  expect(JSON.parse(mock.history.post[0].data)).not.toHaveProperty('details');
});

it('forgets the previous report when reopened', async () => {
  mock.onPost('/reports').reply(201, { data: { report: { id: '1' } }, meta: {} });
  const onClose = jest.fn();

  const view = renderSheet({ onClose });
  fireEvent.press(screen.getByTestId('report-reason-spam'));
  fireEvent.press(screen.getByTestId('report-submit'));
  await screen.findByTestId('report-done');

  // The sheet stays MOUNTED — only the Modal's children unmount — so without a
  // reset the next open would show the last report's confirmation, and a user
  // would think a new one was filed when nothing was sent.
  view.rerender(
    <ReportSheet
      visible={false}
      onClose={onClose}
      target={{ type: 'place', id: '42' }}
      subject="Bar Tinto"
    />,
  );
  view.rerender(
    <ReportSheet visible onClose={onClose} target={{ type: 'place', id: '42' }} subject="Bar Tinto" />,
  );

  expect(screen.queryByTestId('report-done')).toBeNull();
  expect(screen.getByTestId('report-submit').props.accessibilityState.disabled).toBe(true);
});

it('asks a signed-out visitor to sign in instead of failing', async () => {
  useSessionStore.setState({ user: null, status: 'guest' });

  renderSheet();

  // Place pages are public, so this sheet is reachable while signed out. A
  // form that 401s on submit would be worse than one that says so up front.
  expect(screen.getByTestId('report-signed-out')).toBeOnTheScreen();
  expect(screen.queryByTestId('report-submit')).toBeNull();
});

it('clears a previous failure when reopened', async () => {
  mock.onPost('/reports').reply(500);
  const onClose = jest.fn();

  const view = renderSheet({ onClose });
  fireEvent.press(screen.getByTestId('report-reason-spam'));
  fireEvent.press(screen.getByTestId('report-submit'));
  await screen.findByTestId('report-error');

  view.rerender(
    <ReportSheet visible={false} onClose={onClose} target={{ type: 'place', id: '42' }} subject="Bar Tinto" />,
  );
  view.rerender(
    <ReportSheet visible onClose={onClose} target={{ type: 'place', id: '42' }} subject="Bar Tinto" />,
  );

  // The mutation's error state lives in TanStack, not in local state — the
  // three setState resets do not touch it. Without `report.reset()` the red
  // banner is still on screen the next time the sheet opens, blaming the user
  // for a failure that belonged to the previous attempt.
  expect(screen.queryByTestId('report-error')).toBeNull();
});

it('gives a signed-out visitor a way back out', () => {
  useSessionStore.setState({ user: null, status: 'guest' });
  const onClose = jest.fn();

  renderSheet({ onClose });

  // The backdrop is deliberately hidden from assistive tech and
  // `onRequestClose` is Android-only, so without this button the sheet is a
  // trap for a VoiceOver user on a public place page.
  fireEvent.press(screen.getByTestId('report-close'));
  expect(onClose).toHaveBeenCalled();
});
