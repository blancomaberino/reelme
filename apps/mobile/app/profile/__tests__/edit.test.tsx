import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import EditProfileScreen from '../edit';
import { api } from '@/api/client';
import type { Me } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { makeMe } from '@/test/me-fixture';

import { mockRouter } from '../../../jest.setup';

let mock: AxiosMockAdapter;
let qc: QueryClient;

const ME: Me = makeMe({
  name: 'Old Name',
  username: 'marce',
  email: 'm@one.pet',
  // Spelled out because a test below asserts the visibility cards reflect it —
  // a default the fixture merely happens to share would prove nothing.
  is_public: true,
  favorite_topics: ['ramen'],
});

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  mock = new AxiosMockAdapter(api);
  mockRouter.back.mockClear();
  useSessionStore.setState({ status: 'authed', user: ME });
});
afterEach(() => {
  mock.restore();
  qc.clear();
  useSessionStore.setState({ status: 'guest', user: null });
});

it('prefills from the session, edits fields + tags, and PATCHes /me', async () => {
  let sent: Record<string, unknown> = {};
  mock.onPatch('/me').reply((cfg) => {
    sent = JSON.parse(cfg.data);
    return [200, { data: { user: { ...ME, name: 'Marcelo', favorite_topics: ['ramen', 'coffee'] } } }];
  });

  render(<EditProfileScreen />, { wrapper: Providers });

  // Prefilled existing topic chip.
  expect(screen.getByText('ramen')).toBeOnTheScreen();

  fireEvent.changeText(screen.getByLabelText('Name'), 'Marcelo');
  fireEvent.changeText(screen.getByLabelText('Date of birth'), '1990-05-20');

  // Add a topic via the inline input.
  fireEvent.changeText(screen.getByPlaceholderText('Add a topic (e.g. ramen)'), 'coffee');
  fireEvent.press(screen.getAllByLabelText('Add')[0]);
  expect(screen.getByText('coffee')).toBeOnTheScreen();

  // Flip the profile to private before saving.
  fireEvent.press(screen.getByRole('radio', { name: 'Private' }));

  fireEvent.press(screen.getByRole('button', { name: 'Save' }));

  await waitFor(() => expect(mockRouter.back).toHaveBeenCalled());
  expect(sent).toMatchObject({
    name: 'Marcelo',
    birthdate: '1990-05-20',
    favorite_topics: ['ramen', 'coffee'],
    is_public: false,
  });
  // Session mirrors the fresh user.
  expect(useSessionStore.getState().user?.name).toBe('Marcelo');
});

it('prefills visibility from the session (public by default) and keeps it on save', async () => {
  let sent: Record<string, unknown> = {};
  mock.onPatch('/me').reply((cfg) => {
    sent = JSON.parse(cfg.data);
    return [200, { data: { user: ME } }];
  });

  render(<EditProfileScreen />, { wrapper: Providers });

  // ME.is_public is true → the Public option is selected.
  expect(screen.getByRole('radio', { name: 'Public' }).props.accessibilityState?.selected).toBe(true);
  expect(screen.getByRole('radio', { name: 'Private' }).props.accessibilityState?.selected).toBe(false);

  fireEvent.press(screen.getByRole('button', { name: 'Save' }));

  await waitFor(() => expect(mockRouter.back).toHaveBeenCalled());
  expect(sent).toMatchObject({ is_public: true });
});

/**
 * Country (T-110). The field is a picker, not a text input, so the thing worth
 * testing is the whole seam: the control opens the sheet, the sheet's choice
 * comes back as a NAME on screen and a CODE on the wire, and clearing reaches
 * the API as null rather than as "leave it alone".
 */
describe('country', () => {
  const CATALOG = [
    { code: 'ES', name: 'España' },
    { code: 'UY', name: 'Uruguay' },
  ];

  beforeEach(() => {
    mock.onGet('/countries').reply(200, { data: CATALOG });
  });

  it('shows the country stored on the session — no catalog fetch needed to name it', () => {
    useSessionStore.setState({
      status: 'authed',
      user: makeMe({ ...ME, country_code: 'UY', country_name: 'Uruguay' }),
    });

    render(<EditProfileScreen />, { wrapper: Providers });

    // The API already localized it, so it is on screen at first paint. This is
    // also the "survives an app restart" assertion: a relaunch rehydrates the
    // session from /me and lands exactly here. The accessibility label is what
    // a screen-reader user gets, and the only place the field's label and value
    // appear together.
    expect(screen.getByLabelText('Country: Uruguay')).toBeOnTheScreen();

    // And it named it without the 249-row catalog, which is the point of the
    // API sending `country_name` alongside the code.
    expect(mock.history.get.some((r) => r.url === '/countries')).toBe(false);
  });

  it('picks a country in the sheet and PATCHes the CODE', async () => {
    let sent: Record<string, unknown> = {};
    mock.onPatch('/me').reply((cfg) => {
      sent = JSON.parse(cfg.data);
      return [200, { data: { user: { ...ME, country_code: 'ES', country_name: 'España' } } }];
    });

    render(<EditProfileScreen />, { wrapper: Providers });

    // Unset → the field reads as a prompt, not as a value.
    expect(screen.getByLabelText('Country: Choose a country')).toBeOnTheScreen();

    fireEvent.press(screen.getByTestId('country-field'));
    fireEvent.press(await screen.findByTestId('country-row-ES'));

    // The picked country is on screen by name...
    await waitFor(() => expect(screen.getByLabelText('Country: España')).toBeOnTheScreen());

    fireEvent.press(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(mockRouter.back).toHaveBeenCalled());

    // ...and on the wire by code. Sending "España" would 422 against the ISO
    // allow-list, and sending it in a locale the user later switches away from
    // would store the wrong thing entirely.
    expect(sent).toMatchObject({ country_code: 'ES' });
  });

  /**
   * The seed used to require BOTH code and name. A stored code the API could
   * not name then read as "unset", and the next Save — of any unrelated field —
   * PATCHed `country_code: null`, erasing a country the user never touched.
   */
  it('keeps a stored code the API did not name, instead of wiping it on save', async () => {
    useSessionStore.setState({
      status: 'authed',
      user: makeMe({ ...ME, country_code: 'UY', country_name: null }),
    });
    let sent: Record<string, unknown> = {};
    mock.onPatch('/me').reply((cfg) => {
      sent = JSON.parse(cfg.data);
      return [200, { data: { user: ME } }];
    });

    render(<EditProfileScreen />, { wrapper: Providers });

    // Falls back to showing the code — unreadable is still better than lost.
    expect(screen.getByLabelText('Country: UY')).toBeOnTheScreen();

    fireEvent.press(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(mockRouter.back).toHaveBeenCalled());

    expect(sent.country_code).toBe('UY');
  });

  it('clears the country back to null', async () => {
    useSessionStore.setState({
      status: 'authed',
      user: makeMe({ ...ME, country_code: 'UY', country_name: 'Uruguay' }),
    });
    let sent: Record<string, unknown> = {};
    mock.onPatch('/me').reply((cfg) => {
      sent = JSON.parse(cfg.data);
      return [200, { data: { user: { ...ME, country_code: null, country_name: null } } }];
    });

    render(<EditProfileScreen />, { wrapper: Providers });

    fireEvent.press(screen.getByTestId('country-field'));
    fireEvent.press(await screen.findByLabelText('Remove country'));

    await waitFor(() => expect(screen.getByLabelText('Country: Choose a country')).toBeOnTheScreen());

    fireEvent.press(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(mockRouter.back).toHaveBeenCalled());

    // Explicitly null. An omitted key is a partial update the API would ignore,
    // leaving the user unable to un-say where they are.
    expect(sent.country_code).toBeNull();
  });
});

it('removes a tag chip when tapped', async () => {
  mock.onPatch('/me').reply(200, { data: { user: ME } });
  render(<EditProfileScreen />, { wrapper: Providers });

  fireEvent.press(screen.getByLabelText('ramen ✕'));
  expect(screen.queryByText('ramen')).toBeNull();
});
