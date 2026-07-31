/**
 * Files that predate the design tokens (T-104).
 *
 * This is an INVERSE allowlist. The token rule in `eslint.config.js` applies to
 * everything, and these files are exempted because they were written before the
 * scales existed — 55 files carrying 832 raw values between them. T-104
 * migrated the shell (the back header, the Button) rather than restyling all 29
 * screens at once, on purpose: a big-bang diff of 832 numbers is one nobody can
 * review, and the acceptance criterion is no visual regression.
 *
 * **This list may only ever get shorter.** A new file must never be added to
 * it. If a new file needs a value the scale does not have, the scale is wrong —
 * change `src/theme/tokens.ts`. Deleting a line here is the definition of
 * "migrated", and the file is gone when the list empties.
 */
const LEGACY_UNTOKENIZED = [
  'app/(auth)/welcome.tsx',
  'app/(main)/map.tsx',
  'app/(main)/places.tsx',
  'app/(main)/profile.tsx',
  'app/(main)/search.tsx',
  'app/(main)/share.tsx',
  'app/invite.tsx',
  'app/list/\\[slug\\].tsx',
  'app/lists/\\[id\\].tsx',
  'app/lists/index.tsx',
  'app/place/\\[slug\\].tsx',
  'app/profile/edit.tsx',
  'app/settings/index.tsx',
  'app/shares/\\[id\\]/review.tsx',
  'app/shares/\\[id\\]/status.tsx',
  'app/shares/index.tsx',
  'app/tag/\\[slug\\].tsx',
  'app/users/\\[username\\]/followers.tsx',
  'app/users/\\[username\\]/following.tsx',
  'app/users/\\[username\\]/index.tsx',
  'app/users/\\[username\\]/map.tsx',
  'src/components/auth-screen-layout.tsx',
  'src/components/connection-banner.tsx',
  'src/components/error-boundary.tsx',
  'src/components/filters/filter-sheet.tsx',
  'src/components/filters/filter-trigger-bar.tsx',
  'src/components/filters/tag-autocomplete.tsx',
  'src/components/map/cluster-marker.tsx',
  'src/components/map/pin-glyph.tsx',
  'src/components/map/place-sheet.tsx',
  'src/components/map/quick-share.tsx',
  'src/components/place/add-to-list-search.tsx',
  'src/components/place/chip.tsx',
  'src/components/place/menu-sheet.tsx',
  'src/components/place/mini-map.tsx',
  'src/components/place/my-place-card.tsx',
  'src/components/place/my-tags.tsx',
  'src/components/place/place-gallery.tsx',
  'src/components/place/review-composer.tsx',
  'src/components/place/review-sources.tsx',
  'src/components/place/save-to-list.tsx',
  'src/components/place/source-card.tsx',
  'src/components/placeholder-screen.tsx',
  'src/components/profile/follow-list.tsx',
  'src/components/share/pending-venues.tsx',
  'src/components/share/review/candidate-picker.tsx',
  'src/components/share/review/chip-select.tsx',
  'src/components/share/review/confidence-field.tsx',
  'src/components/share/review/dish-editor.tsx',
  'src/components/share/review/evidence-panel.tsx',
  'src/components/share/review/pin-adjuster.tsx',
  'src/components/share/review/price-select.tsx',
  'src/components/share/share-row.tsx',
  'src/components/text-field.tsx',
  'src/components/verify-email-banner.tsx',];

module.exports = { LEGACY_UNTOKENIZED };
