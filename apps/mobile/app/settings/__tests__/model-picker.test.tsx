import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import SettingsScreen from '../index';
import { api } from '@/api/client';
import type { Me } from '@/api/types';
import { useSessionStore } from '@/stores/session';

/**
 * The analysis-model picker in Settings (T-020 API, T-039 UI).
 *
 * Unlike language and currency — which are device state in a zustand store —
 * this is *account* state on the server, so it is authed-only and a tap is a
 * network write. The interesting rules are which models are offered and which
 * are selectable.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

const MODELS = [
  { id: 'auto', label: 'Auto', provider: 'reelmap', available: true },
  { id: 'llava:13b', label: 'LLaVA 13B', provider: 'ollama', available: true },
  { id: 'gpt-4o-mini', label: 'GPT-4o mini', provider: 'openrouter', available: false },
];

function me(over: Partial<Me> = {}): Me {
  return {
    id: '1',
    name: 'Ada',
    username: 'ada',
    email: 'ada@example.com',
    avatar_path: null,
    bio: null,
    birthdate: null,
    age: null,
    favorite_topics: [],
    favorite_foods: [],
    is_influencer: false,
    is_restaurant_owner: false,
    is_admin: false,
    is_public: true,
    preferred_analysis_model: null,
    stripe_connect_onboarded: false,
    email_verified_at: null,
    created_at: null,
    ...over,
  };
}

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
  mock.onGet('/analysis/models').reply(200, { data: { models: MODELS } });
  useSessionStore.setState({ user: me(), status: 'authed' });
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

it('lists the catalog with each model’s provider', async () => {
  render(<SettingsScreen />, { wrapper: Providers });

  expect(await screen.findByText('Auto')).toBeTruthy();
  expect(screen.getByText('LLaVA 13B')).toBeTruthy();
  expect(screen.getByText('ollama')).toBeTruthy();
});

it('defaults the selection to auto when nothing is stored', async () => {
  render(<SettingsScreen />, { wrapper: Providers });

  // `preferred_analysis_model: null` means auto — the picker must show that as
  // a positive choice, not as "nothing selected".
  const auto = await screen.findByLabelText('Auto');
  expect(auto.props.accessibilityState).toMatchObject({ selected: true });
});

it('marks the stored model as selected', async () => {
  useSessionStore.setState({ user: me({ preferred_analysis_model: 'llava:13b' }), status: 'authed' });

  render(<SettingsScreen />, { wrapper: Providers });

  expect((await screen.findByLabelText('LLaVA 13B')).props.accessibilityState).toMatchObject({ selected: true });
  expect(screen.getByLabelText('Auto').props.accessibilityState).toMatchObject({ selected: false });
});

/**
 * An unavailable model is SHOWN but not selectable. Hiding it would make a
 * model the user picked yesterday silently vanish today, with no explanation
 * for why their analyses moved.
 */
it('shows an unavailable model, greyed and unselectable', async () => {
  render(<SettingsScreen />, { wrapper: Providers });

  const unavailable = await screen.findByLabelText('GPT-4o mini');
  expect(unavailable.props.accessibilityState).toMatchObject({ disabled: true });
  expect(screen.getByText('Unavailable right now')).toBeTruthy();

  fireEvent.press(unavailable);
  expect(mock.history.put).toHaveLength(0);
});

it('persists a pick and updates the session so the checkmark moves', async () => {
  mock.onPut('/me/analysis-preference').reply(200, { data: { user: me({ preferred_analysis_model: 'llava:13b' }) } });

  render(<SettingsScreen />, { wrapper: Providers });
  fireEvent.press(await screen.findByLabelText('LLaVA 13B'));

  await waitFor(() => expect(mock.history.put).toHaveLength(1));
  expect(JSON.parse(mock.history.put[0].data)).toEqual({ model: 'llava:13b' });
  await waitFor(() =>
    expect(useSessionStore.getState().user?.preferred_analysis_model).toBe('llava:13b'),
  );
});

it('is hidden from a guest, and does not fetch the catalog', async () => {
  useSessionStore.setState({ user: null, status: 'guest' });

  render(<SettingsScreen />, { wrapper: Providers });

  // Language and currency still render — they are device settings.
  expect(await screen.findByText('Español')).toBeTruthy();
  expect(screen.queryByText('Analysis model')).toBeNull();
  expect(mock.history.get.filter((r) => r.url === '/analysis/models')).toHaveLength(0);
});

it('still lets a signed-in user change language and currency', async () => {
  // The picker is additive — it must not have displaced the device settings.
  render(<SettingsScreen />, { wrapper: Providers });

  expect(await screen.findByText('English')).toBeTruthy();
  expect(screen.getByText('$$$')).toBeTruthy();
});
