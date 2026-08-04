import type { NotificationRow } from '@/api/notifications';
import { translate } from '@/i18n';
import { notificationCopy } from '@/notifications/copy';

/**
 * What a notification row says, per type and per language.
 *
 * The screen test covers this end to end; these cover the table exhaustively,
 * because the failure mode is per-type and silent — a type whose params the
 * server renamed simply starts rendering the frozen server sentence again, and
 * nothing about the screen looks broken.
 */

const t = (locale: 'es' | 'en') => (key: Parameters<typeof translate>[1], params?: Record<string, string | number>) =>
  translate(locale, key, params);

function row(over: Partial<NotificationRow> = {}): NotificationRow {
  return {
    id: 'n1',
    type: 'share.published',
    title: 'SERVER TITLE',
    body: 'SERVER BODY',
    url: '/place/x',
    data: {},
    read_at: null,
    created_at: '2026-07-31T10:00:00Z',
    ...over,
  };
}

describe('known types render from their params', () => {
  it.each([
    ['share.published', { place_name: 'Bar Tinta' }, 'Place added!', 'Bar Tinta is on your map now.'],
    ['share.review_needed', {}, 'Check your place', 'Confirm a few details to finish adding it.'],
    ['share.failed', {}, 'We couldn’t process your link', 'Tap to see what happened and try again.'],
    ['social.follow', { follower_username: 'ana' }, 'New follower', '@ana started following you.'],
    [
      'influencer.claim_rejected',
      { influencer_handle: 'chef' },
      'Claim not approved',
      'Your claim on @chef was not approved.',
    ],
    [
      'redemption.verified',
      { place_name: 'Casa Dingo' },
      'Offer redeemed',
      'Your offer was redeemed at Casa Dingo. Enjoy!',
    ],
    [
      'wallet.payout',
      { amount_minor: 4500, currency: 'EUR' },
      'Payout sent',
      'We sent you €45.00. It lands in your account in a few business days.',
    ],
  ])('%s', (type, data, title, body) => {
    expect(notificationCopy(t('en'), row({ type, data }))).toEqual({ title, body });
  });
});

it('translates the same row into whichever language is active', () => {
  const follow = row({ type: 'social.follow', data: { follower_username: 'ana' } });

  expect(notificationCopy(t('es'), follow).body).toBe('@ana empezó a seguirte.');
  expect(notificationCopy(t('en'), follow).body).toBe('@ana started following you.');
});

it('uses a whole-sentence fallback when a place name is missing', () => {
  // Not the named string with an empty slot — " is on your map now." reads as a
  // truncated sentence, and the two languages drop the subject differently.
  const copy = notificationCopy(t('en'), row({ data: {} }));

  expect(copy.body).toBe('Your place is on the map now.');
  expect(copy.body).not.toContain('  ');
});

it('keeps its translated title when the params are unusable', () => {
  // A legacy `social.follow` row stored before `follower_username` existed:
  // the body is genuinely unknown, but the event still has a name.
  const copy = notificationCopy(t('en'), row({ type: 'social.follow', data: {} }));

  expect(copy.title).toBe('New follower');
  expect(copy.body).toBe('SERVER BODY');
});

it('falls back to the server copy for a type it has never heard of', () => {
  // Forward compatibility: a newer server emits a type this build predates. The
  // copy is frozen in the send-time language, but it is real copy about a real
  // event — far better than dropping the row.
  const copy = notificationCopy(t('en'), row({ type: 'invented.later' }));

  expect(copy).toEqual({ title: 'SERVER TITLE', body: 'SERVER BODY' });
});

it('never surfaces the machine string when there is no copy at all', () => {
  const copy = notificationCopy(t('en'), row({ type: 'invented.later', title: null, body: null }));

  expect(copy.title).toBe('Update');
  expect(copy.title).not.toContain('invented.later');
});

it('ignores empty-string params rather than interpolating a hole', () => {
  const copy = notificationCopy(t('en'), row({ data: { place_name: '' } }));

  expect(copy.body).toBe('Your place is on the map now.');
});
