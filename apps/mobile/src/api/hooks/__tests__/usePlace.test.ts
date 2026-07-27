import type { PlaceDetail } from '@/api/places';
import { galleryPollInterval } from '@/api/hooks/usePlace';

// A just-added place's business gallery (T-099) is enriched asynchronously after
// publish, so the place opens with an empty gallery; galleryPollInterval decides
// how long to keep refetching until it lands.
function place(over: Partial<PlaceDetail> = {}): PlaceDetail {
  return { gallery: [], ...over } as PlaceDetail;
}

it('keeps polling while the gallery is still empty', () => {
  expect(galleryPollInterval(place({ gallery: [] }), 1)).toBe(3_000);
  expect(galleryPollInterval(undefined, 1)).toBe(3_000); // pre-first-fetch
});

it('stops as soon as the gallery arrives', () => {
  const withGallery = place({ gallery: [{ url: 'https://g/a.jpg', source: 'google', attribution: null }] });
  expect(galleryPollInterval(withGallery, 3)).toBe(false);
});

it('stops after the bounded number of fetches so a no-photo place does not poll forever', () => {
  expect(galleryPollInterval(place({ gallery: [] }), 6)).toBe(3_000); // still under the cap
  expect(galleryPollInterval(place({ gallery: [] }), 7)).toBe(false); // cap reached
});
