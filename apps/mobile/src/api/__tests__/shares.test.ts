import { extractUrl, platformFromUrl } from '../shares';

describe('extractUrl', () => {
  it('pulls the URL out of Instagram-style shared text and trims trailing punctuation', () => {
    expect(extractUrl('Check this out! https://www.instagram.com/reel/abc123/ 😍')).toBe(
      'https://www.instagram.com/reel/abc123/',
    );
    expect(extractUrl('great spot (https://maps.app.goo.gl/xyz).')).toBe('https://maps.app.goo.gl/xyz');
  });

  it('returns a bare URL unchanged and null when there is no URL', () => {
    expect(extractUrl('https://tiktok.com/@a/video/1')).toBe('https://tiktok.com/@a/video/1');
    expect(extractUrl('just a caption, no link')).toBeNull();
    expect(extractUrl('')).toBeNull();
  });
});

describe('platformFromUrl', () => {
  it('maps each supported host to its platform', () => {
    expect(platformFromUrl('https://www.instagram.com/reel/x')).toBe('instagram');
    expect(platformFromUrl('https://vm.tiktok.com/abc')).toBe('tiktok');
    expect(platformFromUrl('https://x.com/u/status/1')).toBe('x');
    expect(platformFromUrl('https://twitter.com/u/status/1')).toBe('x');
    expect(platformFromUrl('https://youtu.be/abc')).toBe('youtube');
    expect(platformFromUrl('https://www.youtube.com/shorts/abc')).toBe('youtube');
  });

  it('returns null for unrecognized or malformed input', () => {
    expect(platformFromUrl('https://example.com/x')).toBeNull();
    expect(platformFromUrl('not a url')).toBeNull();
    // Guards against a naive substring match granting a lookalike host.
    expect(platformFromUrl('https://instagram.com.evil.test/x')).toBeNull();
  });
});
