import { render, screen } from '@testing-library/react-native';

import type { PlaceGalleryImage } from '@/api/places';
import { PlaceGallery } from '@/components/place/place-gallery';

function img(over: Partial<PlaceGalleryImage> = {}): PlaceGalleryImage {
  return { url: 'https://cdn.example/x.jpg', source: 'website', attribution: null, ...over };
}

it('shows a photo-credit caption for each attributed image (Google photos)', () => {
  render(
    <PlaceGallery
      images={[
        img({ source: 'website', attribution: null }), // owned → no credit
        img({ url: 'https://g/a.jpg', source: 'google', attribution: 'Alpha Diner' }),
        img({ url: 'https://g/b.jpg', source: 'google', attribution: 'Beta Cafe' }),
      ]}
    />,
  );

  // Each attributed slide credits it as a Google review; the owned website image
  // has no credit.
  expect(screen.getByText('Google review · Alpha Diner')).toBeOnTheScreen();
  expect(screen.getByText('Google review · Beta Cafe')).toBeOnTheScreen();
});

it('renders no photo credits when no image carries attribution', () => {
  render(<PlaceGallery testID="place-gallery" images={[img(), img({ url: 'https://cdn.example/2.jpg' })]} />);

  expect(screen.getByTestId('place-gallery')).toBeOnTheScreen();
  // A website-only gallery shows the images but never an attribution scrim.
  expect(screen.queryByText('website')).toBeNull();
});

it('tolerates a non-http url without crashing (Thumbnail placeholder)', () => {
  render(<PlaceGallery testID="place-gallery" images={[img(), img({ url: 'not-a-url' })]} />);

  expect(screen.getByTestId('place-gallery')).toBeOnTheScreen();
});
