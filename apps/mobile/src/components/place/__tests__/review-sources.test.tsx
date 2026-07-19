import { fireEvent, render, screen } from '@testing-library/react-native';

import type { ReviewSourceSummary } from '@/api/places';
import { ReviewSources } from '@/components/place/review-sources';
import { openWebUrl } from '@/lib/linking';

jest.mock('@/lib/linking', () => ({ openWebUrl: jest.fn() }));

const openWebUrlMock = openWebUrl as jest.MockedFunction<typeof openWebUrl>;

function summary(over: Partial<ReviewSourceSummary> = {}): ReviewSourceSummary {
  return { source: 'google', rating: 4.5, count: 320, url: null, synced_at: null, snippets: [], ...over };
}

afterEach(() => openWebUrlMock.mockClear());

it('renders nothing when there are no sources', () => {
  const { toJSON } = render(<ReviewSources sources={[]} />);
  expect(toJSON()).toBeNull();
});

it('labels each known source and shows its rating + count', () => {
  render(
    <ReviewSources
      sources={[
        summary({ source: 'native', rating: 4.2, count: 2, url: null }),
        summary({ source: 'google', rating: 4.5, count: 320, url: 'https://g/x' }),
        summary({ source: 'trustpilot', rating: 3.8, count: 1200, url: 'https://tp/x' }),
      ]}
    />,
  );

  expect(screen.getByText('Reelmap')).toBeOnTheScreen();
  expect(screen.getByText('Google')).toBeOnTheScreen();
  expect(screen.getByText('Trustpilot')).toBeOnTheScreen();
  // Ratings render to one decimal; counts pluralize (rating + count share a node).
  expect(screen.getByText(/^4\.5/)).toBeOnTheScreen();
  expect(screen.getByText(/320 reviews/)).toBeOnTheScreen();
});

it('opens the deep link only for a source that carries a url', () => {
  render(
    <ReviewSources
      sources={[
        summary({ source: 'google', url: 'https://search.google/x' }),
        summary({ source: 'native', url: null }),
      ]}
    />,
  );

  fireEvent.press(screen.getByLabelText('Read on Google'));
  expect(openWebUrlMock).toHaveBeenCalledWith('https://search.google/x');

  // The native row is not a link (no url) — no read affordance rendered.
  expect(screen.queryByLabelText('Read on Reelmap')).toBeNull();
});

it('shows an em-dash for a source with no rating', () => {
  render(<ReviewSources sources={[summary({ source: 'trustpilot', rating: null, count: 0, url: 'https://tp/x' })]} />);
  expect(screen.getByText(/^—/)).toBeOnTheScreen();
});
