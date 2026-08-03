import * as WebBrowser from 'expo-web-browser';

/**
 * Open Stripe's hosted KYC in a system auth session (T-046, 05 screen #22).
 *
 * A real browser, not an in-app WebView, and that is deliberate: this flow asks
 * for ID documents and bank details, so the user should see a URL bar saying
 * `stripe.com`. An app that renders KYC inside its own chrome is
 * indistinguishable from one harvesting it.
 *
 * `openAuthSessionAsync` also closes ITSELF when Stripe redirects to our return
 * URL, so there is no navigation to intercept by hand — which is the part of a
 * WebView implementation most likely to be subtly wrong.
 *
 * The result is deliberately not trusted. A `dismiss` can mean "finished" or
 * "gave up", and Stripe's own verification is asynchronous besides — so the
 * caller always re-reads the live Connect status rather than inferring anything
 * from how the browser closed.
 */
export async function openStripeOnboarding(url: string): Promise<void> {
  try {
    await WebBrowser.openAuthSessionAsync(url, RETURN_SCHEME);
  } catch {
    // A browser that refuses to open is not worth crashing a wallet over; the
    // caller refetches either way and the CTA stays available.
  }
}

/**
 * The scheme the session watches for. The API hands Stripe an HTTPS route
 * (Stripe rejects custom schemes in live mode) which redirects here.
 */
const RETURN_SCHEME = 'reelmap://wallet/connect';
