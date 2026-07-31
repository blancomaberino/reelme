import { onlineManager, QueryClient, QueryClientProvider, useMutation } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import { api } from '@/api/client';
import { NetworkError, ValidationError } from '@/api/types';
import { formErrors } from '@/lib/form-errors';
import { translate } from '@/i18n';

/**
 * A write attempted with no connection must FAIL, loudly and immediately
 * (T-103). React Query's default `networkMode: 'online'` would instead park it
 * and replay it whenever the network returned — possibly minutes later, on a
 * screen the user has long left, against state they have since changed. The
 * app opts writes out of that; these tests pin the behaviour, because the
 * default is one config line away from coming back.
 */

let mock: AxiosMockAdapter;

beforeEach(() => {
  mock = new AxiosMockAdapter(api);
});

afterEach(() => {
  mock.restore();
  onlineManager.setOnline(true);
});

describe('the API client', () => {
  it('turns a request that never reached the API into a NetworkError', async () => {
    mock.onPost('/lists').networkError();

    await expect(api.post('/lists', { name: 'Tapas' })).rejects.toBeInstanceOf(NetworkError);
  });

  it('turns a timeout into a NetworkError too — no response is no response', async () => {
    mock.onPost('/lists').timeout();

    await expect(api.post('/lists', { name: 'Tapas' })).rejects.toBeInstanceOf(NetworkError);
  });

  it('leaves a server error alone — a 500 DID reach the API', async () => {
    mock.onPost('/lists').reply(500);

    await expect(api.post('/lists', { name: 'Tapas' })).rejects.not.toBeInstanceOf(NetworkError);
  });

  it('still prefers the typed 422 over the network branch', async () => {
    mock.onPost('/lists').reply(422, { error: { details: { name: ['Required.'] } } });

    await expect(api.post('/lists', {})).rejects.toBeInstanceOf(ValidationError);
  });
});

describe('formErrors', () => {
  it('tells the user the connection is the problem, in both locales', () => {
    const { generalError } = formErrors(new NetworkError());

    expect(generalError).toBe('common.error.offline');
    expect(translate('en', generalError!)).toBe('No connection. Check your network and try again.');
    expect(translate('es', generalError!)).toBe('Sin conexión. Revisa tu red e inténtalo de nuevo.');
  });

  it('falls back to the generic message for anything else', () => {
    expect(formErrors(new Error('boom')).generalError).toBe('common.error.general');
  });

  it('reports field errors instead of a general one for a 422', () => {
    const { fieldErrors, generalError } = formErrors(new ValidationError({ name: 'Required.' }));

    expect(fieldErrors).toEqual({ name: 'Required.' });
    expect(generalError).toBeNull();
  });
});

describe('an offline mutation', () => {
  function wrapper(client: QueryClient) {
    return function Wrapper({ children }: { children: ReactNode }) {
      return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
    };
  }

  it('errors immediately instead of parking and replaying later', async () => {
    // Same defaults as the app's root client (app/_layout.tsx).
    const client = new QueryClient({ defaultOptions: { mutations: { retry: 0, networkMode: 'always' } } });
    mock.onPost('/lists').networkError();
    onlineManager.setOnline(false);

    const { result } = renderHook(() => useMutation({ mutationFn: () => api.post('/lists', { name: 'Tapas' }) }), {
      wrapper: wrapper(client),
    });

    act(() => result.current.mutate());

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.error).toBeInstanceOf(NetworkError);
    // The failure mode this exists to prevent: a pending write held in limbo.
    expect(result.current.isPaused).toBe(false);
    expect(formErrors(result.current.error).generalError).toBe('common.error.offline');
  });

  it('is not resurrected when the connection returns', async () => {
    const client = new QueryClient({ defaultOptions: { mutations: { retry: 0, networkMode: 'always' } } });
    mock.onPost('/lists').networkError();
    onlineManager.setOnline(false);

    const { result } = renderHook(() => useMutation({ mutationFn: () => api.post('/lists', { name: 'Tapas' }) }), {
      wrapper: wrapper(client),
    });
    act(() => result.current.mutate());
    await waitFor(() => expect(result.current.isError).toBe(true));

    mock.resetHistory();
    mock.onPost('/lists').reply(201, { data: { id: '1' } });
    await act(async () => {
      onlineManager.setOnline(true);
      await client.resumePausedMutations();
    });

    // Nothing was queued, so nothing replays: the user retries deliberately.
    expect(mock.history.post).toHaveLength(0);
    expect(result.current.isError).toBe(true);
  });
});
