import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import type { ReactNode } from 'react';

import TwoFactorScreen from '../settings/two-factor';
import { api } from '@/api/client';
import type { TwoFactorStatus } from '@/api/two-factor';

/**
 * The 2FA setup / manage screen (T-068).
 *
 * The rules pinned here are the ones where a plausible screen quietly does the
 * wrong thing: showing recovery codes without ever having them, letting a
 * destructive action through without the password, or treating a half-finished
 * setup as if it were on.
 */

let mock: AxiosMockAdapter;
let qc: QueryClient;

const OFF: TwoFactorStatus = { enabled: false, pending: false, confirmed_at: null, recovery_codes_remaining: 0 };
const ON: TwoFactorStatus = {
  enabled: true,
  pending: false,
  confirmed_at: '2026-08-01T10:00:00Z',
  recovery_codes_remaining: 8,
};

const QR = 'data:image/png;base64,iVBORw0KGgo=';

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: 0 } } });
  mock = new AxiosMockAdapter(api);
});

afterEach(() => {
  mock.restore();
  qc.clear();
});

describe('setup', () => {
  it('renders the server-rendered QR and the manual key', async () => {
    mock.onGet('/two-factor').reply(200, { data: OFF });
    mock.onPost('/two-factor/enable').reply(200, {
      data: { secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/x', qr_png: QR },
    });

    render(<TwoFactorScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('start-setup'));

    // The QR is an <Image> fed a data URI — no QR library, hence no native
    // module and no dev-client rebuild.
    const qr = await screen.findByTestId('two-factor-qr');
    expect(qr.props.source).toEqual({ uri: QR });
    // The raw secret is there too: an authenticator that cannot scan needs it.
    expect(screen.getByTestId('two-factor-secret')).toHaveTextContent('JBSWY3DPEHPK3PXP');
  });

  it('confirms with a code and then shows the recovery codes', async () => {
    mock.onGet('/two-factor').reply(200, { data: OFF });
    mock.onPost('/two-factor/enable').reply(200, {
      data: { secret: 'S', otpauth_uri: 'otpauth://totp/x', qr_png: QR },
    });
    mock.onPost('/two-factor/confirm').reply(200, { data: { recovery_codes: ['AAAA-BBBB', 'CCCC-DDDD'] } });

    render(<TwoFactorScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('start-setup'));
    fireEvent.changeText(await screen.findByTestId('confirm-code'), '123456');
    fireEvent.press(screen.getByTestId('confirm-submit'));

    // Shown once, right here — this is the only moment the user is guaranteed
    // to be looking at them.
    expect(await screen.findByTestId('recovery-codes')).toBeTruthy();
    expect(screen.getByText('AAAA-BBBB')).toBeTruthy();
    expect(screen.getByText('CCCC-DDDD')).toBeTruthy();
  });

  it('will not confirm a half-typed code', async () => {
    mock.onGet('/two-factor').reply(200, { data: OFF });
    mock.onPost('/two-factor/enable').reply(200, {
      data: { secret: 'S', otpauth_uri: 'otpauth://totp/x', qr_png: QR },
    });

    render(<TwoFactorScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('start-setup'));
    fireEvent.changeText(await screen.findByTestId('confirm-code'), '12');
    fireEvent.press(screen.getByTestId('confirm-submit'));

    expect(mock.history.post.filter((r) => r.url === '/two-factor/confirm')).toHaveLength(0);
  });

  it('offers to finish an abandoned setup rather than starting over', async () => {
    // A secret with no confirmation is "started", not "on". Rendering it as OFF
    // with a fresh Set up is fine; rendering it as ON would lock the user out.
    mock.onGet('/two-factor').reply(200, { data: { ...OFF, pending: true } });

    render(<TwoFactorScreen />, { wrapper });

    expect(await screen.findByTestId('start-setup')).toHaveTextContent('Finish setting up');
  });
});

describe('when it is on', () => {
  it('shows how many recovery codes are left', async () => {
    mock.onGet('/two-factor').reply(200, { data: ON });

    render(<TwoFactorScreen />, { wrapper });

    expect(await screen.findByTestId('codes-remaining')).toHaveTextContent('8 recovery codes left');
  });

  it('warns when the codes are nearly gone', async () => {
    // The failure mode people discover at the worst possible moment.
    mock.onGet('/two-factor').reply(200, { data: { ...ON, recovery_codes_remaining: 1 } });

    render(<TwoFactorScreen />, { wrapper });

    expect(await screen.findByText(/Running low/)).toBeTruthy();
    expect(screen.getByTestId('codes-remaining')).toHaveTextContent('1 recovery code left');
  });

  it('asks for the password before turning it off, and sends it', async () => {
    mock.onGet('/two-factor').reply(200, { data: ON });
    mock.onDelete('/two-factor').reply(200, { data: { enabled: false } });

    render(<TwoFactorScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('disable-2fa'));

    // No request yet — the tap only opens the confirmation.
    expect(mock.history.delete).toHaveLength(0);

    fireEvent.changeText(screen.getByTestId('password-input'), 'secret123!');
    fireEvent.press(screen.getByTestId('password-submit'));

    await waitFor(() => expect(mock.history.delete).toHaveLength(1));
    expect(JSON.parse(mock.history.delete[0].data)).toEqual({ password: 'secret123!' });
  });

  it('will not submit an empty password', async () => {
    mock.onGet('/two-factor').reply(200, { data: ON });

    render(<TwoFactorScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('disable-2fa'));
    fireEvent.press(screen.getByTestId('password-submit'));

    expect(mock.history.delete).toHaveLength(0);
  });

  it('keeps 2FA on when the password is wrong', async () => {
    mock.onGet('/two-factor').reply(200, { data: ON });
    mock.onDelete('/two-factor').reply(422, {
      error: { code: 'validation_failed', message: 'bad', details: { password: ['Wrong password.'] } },
    });

    render(<TwoFactorScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('disable-2fa'));
    fireEvent.changeText(screen.getByTestId('password-input'), 'wrong');
    fireEvent.press(screen.getByTestId('password-submit'));

    // Still on the confirmation step with the error, not silently dismissed
    // back to a screen that looks like it worked.
    expect(await screen.findByText('Wrong password.')).toBeTruthy();
    expect(screen.getByTestId('password-input')).toBeTruthy();
  });

  it('reveals the existing codes after a password', async () => {
    mock.onGet('/two-factor').reply(200, { data: ON });
    mock.onPost('/two-factor/recovery-codes').reply(200, { data: { recovery_codes: ['EEEE-FFFF'] } });

    render(<TwoFactorScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('view-codes'));
    fireEvent.changeText(screen.getByTestId('password-input'), 'secret123!');
    fireEvent.press(screen.getByTestId('password-submit'));

    expect(await screen.findByText('EEEE-FFFF')).toBeTruthy();
  });

  it('warns that regenerating kills the current codes before doing it', async () => {
    mock.onGet('/two-factor').reply(200, { data: ON });
    mock.onPost('/two-factor/recovery-codes/regenerate').reply(200, { data: { recovery_codes: ['GGGG-HHHH'] } });

    render(<TwoFactorScreen />, { wrapper });
    fireEvent.press(await screen.findByTestId('regenerate-codes'));

    // The warning has to precede the action: afterwards is too late, the old
    // list on paper is already dead.
    expect(screen.getByText(/stop working right away/)).toBeTruthy();

    fireEvent.changeText(screen.getByTestId('password-input'), 'secret123!');
    fireEvent.press(screen.getByTestId('password-submit'));

    expect(await screen.findByText('GGGG-HHHH')).toBeTruthy();
  });
});
