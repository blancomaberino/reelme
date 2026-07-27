import { galleryPollInterval } from '@/api/hooks/usePlace';

// A just-added place's business gallery (T-099) is enriched asynchronously after
// publish, so the place opens with an empty gallery; galleryPollInterval decides
// how long to keep refetching until it lands. (Typed via the fn's own signature
// to avoid a type-only import.)
function data(gallery: unknown[]): Parameters<typeof galleryPollInterval>[0] {
  return { gallery } as Parameters<typeof galleryPollInterval>[0];
}

it('keeps polling while the gallery is still empty', () => {
  expect(galleryPollInterval(data([]), 1)).toBe(3_000);
  expect(galleryPollInterval(undefined, 1)).toBe(3_000); // pre-first-fetch
});

it('stops as soon as the gallery arrives', () => {
  expect(galleryPollInterval(data([{ url: 'https://g/a.jpg', source: 'google', attribution: null }]), 3)).toBe(false);
});

it('stops after the bounded number of fetches so a no-photo place does not poll forever', () => {
  expect(galleryPollInterval(data([]), 6)).toBe(3_000); // still under the cap
  expect(galleryPollInterval(data([]), 7)).toBe(false); // cap reached
});
