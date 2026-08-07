import { fireEvent, render, screen } from '@testing-library/react-native';

import { ShareRow } from '../share-row';
import { mockRouter } from '../../../../jest.setup';
import { shareDetail } from '@/test/share-fixtures';

/**
 * The row shared by the composer's "recent shares" and the "my shares" list.
 *
 * It has one branch and that branch decides where a tap goes: a PUBLISHED share
 * opens its place, anything else opens the status screen so it can be watched
 * or resumed. Getting it backwards sends somebody to a progress bar for work
 * that finished days ago — which looks like the app is stuck.
 *
 * The component had no tests at all before T-053.
 */
beforeEach(() => {
  mockRouter.push.mockClear();
});

const place = { id: '9', name: 'Clara Café', lat: -34.9, lng: -56.1 };

it('opens the place when the share is published', () => {
  render(<ShareRow share={shareDetail({ status: 'published', place })} />);

  fireEvent.press(screen.getByRole('button'));

  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/place/[slug]', params: { slug: '9' } });
});

it('opens the status screen when there is no place yet', () => {
  render(<ShareRow share={shareDetail({ id: '42', status: 'analyzing', place: null })} />);

  fireEvent.press(screen.getByRole('button'));

  expect(mockRouter.push).toHaveBeenCalledWith({ pathname: '/shares/[id]/status', params: { id: '42' } });
});

it('says the status out loud, not only in the coloured pill', () => {
  render(<ShareRow share={shareDetail({ status: 'published', place })} />);

  // An `accessible` Pressable COLLAPSES its children, so the badge's text is
  // absent from the accessibility tree entirely — a screen-reader user heard
  // "Clara Café, button" and had no way to tell a published share from one
  // still in review. That distinction is the only thing in the row that changes
  // what tapping it does.
  expect(screen.getByLabelText(/Clara Café.*Published/i)).toBeOnTheScreen();
});

it('falls back to the post URL when there is no place name', () => {
  render(
    <ShareRow
      share={shareDetail({
        status: 'analyzing',
        place: null,
        source_post: {
          id: '1',
          platform: 'instagram',
          url: 'https://www.instagram.com/reel/ABC/',
          author_handle: null,
          caption: null,
          fetch_status: 'ok',
        },
      })}
    />,
  );

  // A share in flight has no place name yet, and a row reading "—" is one a
  // user cannot tell apart from the three others they just pasted.
  expect(screen.getByLabelText(/instagram\.com\/reel\/ABC/)).toBeOnTheScreen();
});
