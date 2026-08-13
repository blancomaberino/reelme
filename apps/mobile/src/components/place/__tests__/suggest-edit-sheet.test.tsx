import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import AxiosMockAdapter from 'axios-mock-adapter';
import { type ReactNode } from 'react';
import { Alert } from 'react-native';

import { api } from '@/api/client';
import type { PlaceDetail } from '@/api/places';
import { SuggestEditSheet } from '@/components/place/suggest-edit-sheet';

let mock: AxiosMockAdapter;
let qc: QueryClient;

function Providers({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

function makePlace(overrides: Partial<PlaceDetail> = {}): PlaceDetail {
  return {
    id: '1',
    name: 'Cantina Vieja',
    slug: 'cantina-vieja-abc123',
    status: 'active',
    lat: -34.9,
    lng: -56.16,
    category: null,
    price_range: null,
    city: 'Montevideo',
    country_code: 'UY',
    address: 'Bartolomé Mitre 1327, Montevideo, UY',
    address_line1: 'Bartolomé Mitre 1327',
    can_edit: false,
    google_place_id: null,
    opening_hours: null,
    phone: '+598 2 111 1111',
    website: null,
    image_url: null,
    thumbnail_url: null,
    cuisines: [],
    vibe_tags: [],
    dietary_tags: [],
    dishes: [],
    dishes_updated_at: null,
    dishes_language: null,
    source_count: 1,
    rating: { google: { value: null, count: 0 }, app: { value: null, count: 0 } },
    discounts: [],
    ...overrides,
  } as PlaceDetail;
}

/** The API's answer for a queued proposal — status `pending`. */
function queued() {
  return {
    data: {
      id: '9',
      place_id: '1',
      status: 'pending',
      is_owner_submission: false,
      changes: [{ field: 'phone', from: '+598 2 111 1111', to: '+598 2 900 0000' }],
      created_at: '2026-08-12T10:00:00+00:00',
      reviewed_at: null,
    },
  };
}

beforeEach(() => {
  qc = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 }, mutations: { retry: false } } });
  mock = new AxiosMockAdapter(api);
});
afterEach(() => {
  mock.restore();
  qc.clear();
  jest.restoreAllMocks();
});

it('prefills every field from the place, so nothing is corrected blind', () => {
  render(<SuggestEditSheet visible onClose={jest.fn()} place={makePlace()} onQueued={jest.fn()} />, {
    wrapper: Providers,
  });

  expect(screen.getByTestId('suggest-name').props.value).toBe('Cantina Vieja');
  expect(screen.getByTestId('suggest-address_line1').props.value).toBe('Bartolomé Mitre 1327');
  expect(screen.getByTestId('suggest-city').props.value).toBe('Montevideo');
  expect(screen.getByTestId('suggest-phone').props.value).toBe('+598 2 111 1111');
  // Absent on the place, so empty rather than the string "null".
  expect(screen.getByTestId('suggest-website').props.value).toBe('');
});

it('keeps the button disabled until something actually differs', () => {
  render(<SuggestEditSheet visible onClose={jest.fn()} place={makePlace()} onQueued={jest.fn()} />, {
    wrapper: Providers,
  });

  const submit = screen.getByTestId('suggest-submit');
  expect(submit).toBeDisabled();

  // Whitespace-only edits are not edits — the API would answer 422 and the
  // person would have met an error by tapping the only button on the sheet.
  fireEvent.changeText(screen.getByTestId('suggest-phone'), '  +598 2 111 1111  ');
  expect(submit).toBeDisabled();

  fireEvent.changeText(screen.getByTestId('suggest-phone'), '+598 2 900 0000');
  expect(submit).toBeEnabled();
});

it('will not submit a blank name — the column is NOT NULL', () => {
  render(<SuggestEditSheet visible onClose={jest.fn()} place={makePlace()} onQueued={jest.fn()} />, {
    wrapper: Providers,
  });

  fireEvent.changeText(screen.getByTestId('suggest-name'), '');

  expect(screen.getByTestId('suggest-submit')).toBeDisabled();
});

it('sends only the changed fields, and clears an emptied optional one', async () => {
  mock.onPost('/places/cantina-vieja-abc123/suggestions').reply(201, queued());

  render(
    <SuggestEditSheet
      visible
      onClose={jest.fn()}
      place={makePlace({ website: 'https://old.example' })}
      onQueued={jest.fn()}
    />,
    { wrapper: Providers },
  );

  fireEvent.changeText(screen.getByTestId('suggest-phone'), '+598 2 900 0000');
  fireEvent.changeText(screen.getByTestId('suggest-website'), '');
  fireEvent.press(screen.getByTestId('suggest-submit'));

  await waitFor(() => expect(mock.history.post).toHaveLength(1));

  // Untouched fields are absent, not sent back unchanged: the API diffs what it
  // receives, and echoing the whole form would file "changes" nobody made.
  expect(JSON.parse(mock.history.post[0].data)).toEqual({
    phone: '+598 2 900 0000',
    website: null,
  });
});

it('tells a suggester their change is queued, and closes', async () => {
  mock.onPost('/places/cantina-vieja-abc123/suggestions').reply(201, queued());
  const onClose = jest.fn();
  const onQueued = jest.fn();

  render(<SuggestEditSheet visible onClose={onClose} place={makePlace()} onQueued={onQueued} />, {
    wrapper: Providers,
  });

  fireEvent.changeText(screen.getByTestId('suggest-phone'), '+598 2 900 0000');
  fireEvent.press(screen.getByTestId('suggest-submit'));

  await waitFor(() => expect(onQueued).toHaveBeenCalled());
  expect(onClose).toHaveBeenCalled();
});

it('gives an operator no receipt — the change is already on the screen behind', async () => {
  mock.onPost('/places/cantina-vieja-abc123/suggestions').reply(201, {
    data: { ...queued().data, status: 'approved', is_owner_submission: true },
  });
  const onQueued = jest.fn();
  const onClose = jest.fn();

  render(
    <SuggestEditSheet visible onClose={onClose} place={makePlace({ can_edit: true })} onQueued={onQueued} />,
    { wrapper: Providers },
  );

  fireEvent.changeText(screen.getByTestId('suggest-phone'), '+598 2 900 0000');
  fireEvent.press(screen.getByTestId('suggest-submit'));

  await waitFor(() => expect(onClose).toHaveBeenCalled());
  expect(onQueued).not.toHaveBeenCalled();
});

/**
 * The two framings are the same form, so the only thing separating them is
 * copy. If that copy ever collapses, an operator is told their live edit needs
 * review — or worse, a diner is told theirs goes live immediately.
 */
it('says the change goes to review, and to an operator that it does not', () => {
  const { rerender } = render(
    <SuggestEditSheet visible onClose={jest.fn()} place={makePlace()} onQueued={jest.fn()} />,
    { wrapper: Providers },
  );

  // English copy: the jest harness renders the `en` dictionary. es.ts is held
  // to the same key set by the i18n parity test, so asserting one side here is
  // enough to prove the two framings differ.
  expect(screen.getByText(/A moderator reviews changes/i)).toBeOnTheScreen();
  expect(screen.getByText('Send suggestion')).toBeOnTheScreen();

  rerender(
    <SuggestEditSheet visible onClose={jest.fn()} place={makePlace({ can_edit: true })} onQueued={jest.fn()} />,
  );

  expect(screen.getByText(/go live right away/i)).toBeOnTheScreen();
  expect(screen.getByText('Save')).toBeOnTheScreen();
});

it('surfaces a failed submit instead of closing on it', async () => {
  mock.onPost('/places/cantina-vieja-abc123/suggestions').reply(500);
  const onClose = jest.fn();

  render(<SuggestEditSheet visible onClose={onClose} place={makePlace()} onQueued={jest.fn()} />, {
    wrapper: Providers,
  });

  fireEvent.changeText(screen.getByTestId('suggest-phone'), '+598 2 900 0000');
  fireEvent.press(screen.getByTestId('suggest-submit'));

  expect(await screen.findByText('Something went wrong. Please try again.')).toBeOnTheScreen();
  expect(onClose).not.toHaveBeenCalled();
});

/**
 * The sheet's state is owned OUTSIDE the Modal, so RN unmounting the children
 * on close does not reset it. Without the re-seed on open, the next person to
 * open it meets somebody's abandoned half-typed edit.
 */
it('drops an abandoned edit when the sheet is re-opened', () => {
  const place = makePlace();
  const { rerender } = render(
    <SuggestEditSheet visible onClose={jest.fn()} place={place} onQueued={jest.fn()} />,
    { wrapper: Providers },
  );

  fireEvent.changeText(screen.getByTestId('suggest-name'), 'Typo Typo Typo');
  rerender(<SuggestEditSheet visible={false} onClose={jest.fn()} place={place} onQueued={jest.fn()} />);
  rerender(<SuggestEditSheet visible onClose={jest.fn()} place={place} onQueued={jest.fn()} />);

  expect(screen.getByTestId('suggest-name').props.value).toBe('Cantina Vieja');
  expect(screen.getByTestId('suggest-submit')).toBeDisabled();
});

it('never fires an Alert of its own — the receipt is the caller\'s to give', async () => {
  const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
  mock.onPost('/places/cantina-vieja-abc123/suggestions').reply(201, queued());

  render(<SuggestEditSheet visible onClose={jest.fn()} place={makePlace()} onQueued={jest.fn()} />, {
    wrapper: Providers,
  });

  fireEvent.changeText(screen.getByTestId('suggest-phone'), '+598 2 900 0000');
  fireEvent.press(screen.getByTestId('suggest-submit'));

  await waitFor(() => expect(mock.history.post).toHaveLength(1));
  expect(alert).not.toHaveBeenCalled();
});

/**
 * `SheetShell` hides its ✕ when a footer is present, on the reasoning that a
 * footer IS the way out — true for the filter sheet's "Apply", false here: this
 * footer is disabled until something changes, so a sheet opened and read would
 * offer a dead button and an unlabelled backdrop and nothing else.
 */
it('keeps a visible way out even while the only footer button is dead', () => {
  const onClose = jest.fn();

  render(<SuggestEditSheet visible onClose={onClose} place={makePlace()} onQueued={jest.fn()} />, {
    wrapper: Providers,
  });

  expect(screen.getByTestId('suggest-submit')).toBeDisabled();

  fireEvent.press(screen.getByTestId('sheet-close'));
  expect(onClose).toHaveBeenCalled();
});
