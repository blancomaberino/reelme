import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import RegisterScreen from '../register';
import { api } from '@/api/client';
import { useSettingsStore } from '@/stores/settings';

/**
 * The signup age gate, client side (T-113).
 *
 * The server enforces it; this covers the half the server cannot: that the
 * refusal reaches the user AS A SENTENCE IN THEIR LANGUAGE, attached to the
 * field it is about. The API sends only a number — everything a person actually
 * reads here is built in the app.
 */

let qc: QueryClient;
let mock: AxiosMockAdapter;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

/** The envelope the API returns for an under-age signup. */
function ageRestricted(minimumAge = 13) {
  return {
    error: {
      code: 'age_restricted',
      message: `You need to be at least ${minimumAge} to use Reelmap.`,
      details: { minimum_age: minimumAge, field: 'date_of_birth' },
    },
  };
}

async function fillAndSubmit(dateOfBirth = '2015-06-01') {
  fireEvent.changeText(screen.getByTestId('register-date-of-birth'), dateOfBirth);
  await act(async () => {
    fireEvent.press(screen.getByText('Create account'));
  });
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { mutations: { retry: 0 }, queries: { retry: false } } });
  mock = new AxiosMockAdapter(api);
  useSettingsStore.setState({ locale: 'en' });
});

afterEach(() => {
  mock.restore();
});

it('asks for a date of birth and says it is not kept', () => {
  render(<RegisterScreen />, { wrapper: Providers });

  expect(screen.getByTestId('register-date-of-birth')).toBeTruthy();
  // The promise the whole design exists to be able to make. If the field ever
  // starts being stored, this copy becomes a lie and should fail loudly.
  expect(screen.getByText('We only use this to check your age. It is not saved.')).toBeTruthy();
});

it('sends the date of birth with the registration', async () => {
  mock.onPost('/auth/register').reply(422, ageRestricted());

  render(<RegisterScreen />, { wrapper: Providers });
  await fillAndSubmit('2015-06-01');

  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  expect(JSON.parse(mock.history.post[0].data)).toMatchObject({ date_of_birth: '2015-06-01' });
});

it('shows the refusal in the user’s language, not the API’s', async () => {
  mock.onPost('/auth/register').reply(422, ageRestricted(13));

  render(<RegisterScreen />, { wrapper: Providers });
  await fillAndSubmit();

  // The API's own message is English. This assertion passes only because the
  // app rebuilt the sentence from the number.
  await waitFor(() => expect(screen.getByText('You need to be at least 13 to use Reelmap.')).toBeTruthy());
});

it('says it in Spanish when the app is in Spanish', async () => {
  useSettingsStore.setState({ locale: 'es' });
  mock.onPost('/auth/register').reply(422, ageRestricted(13));

  render(<RegisterScreen />, { wrapper: Providers });
  fireEvent.changeText(screen.getByTestId('register-date-of-birth'), '2015-06-01');
  await act(async () => {
    fireEvent.press(screen.getByText('Crear cuenta'));
  });

  await waitFor(() =>
    expect(screen.getByText('Tenés que tener al menos 13 años para usar Reelmap.')).toBeTruthy(),
  );
});

it('quotes the minimum the server actually sent, not a hardcoded one', async () => {
  // The reason the number travels as data. If the server's minimum is raised,
  // the app must say the new one without being rebuilt — a mirrored constant
  // here would still be confidently saying 13.
  mock.onPost('/auth/register').reply(422, ageRestricted(16));

  render(<RegisterScreen />, { wrapper: Providers });
  await fillAndSubmit();

  await waitFor(() => expect(screen.getByText('You need to be at least 16 to use Reelmap.')).toBeTruthy());
});

it('does not mangle the age refusal into stray field errors', async () => {
  // `age_restricted` is a 422 whose details are `{minimum_age, field}` rather
  // than message arrays. Handled by the generic 422 branch it would surface as
  // a field error reading "13" under a field called `minimum_age`.
  mock.onPost('/auth/register').reply(422, ageRestricted(13));

  render(<RegisterScreen />, { wrapper: Providers });
  await fillAndSubmit();

  await waitFor(() => expect(screen.getByText('You need to be at least 13 to use Reelmap.')).toBeTruthy());
  expect(screen.queryByText('13')).toBeNull();
  expect(screen.queryByText('date_of_birth')).toBeNull();
});

it('keeps the typed date after a failure so it need not be retyped', async () => {
  mock.onPost('/auth/register').reply(422, {
    error: { code: 'validation_failed', message: 'Invalid', details: { username: ['Taken.'] } },
  });

  render(<RegisterScreen />, { wrapper: Providers });
  await fillAndSubmit('1990-04-04');

  await waitFor(() => expect(screen.getByText('Taken.')).toBeTruthy());
  expect(screen.getByTestId('register-date-of-birth').props.value).toBe('1990-04-04');
});
