<?php

use App\Models\Influencer;
use App\Models\Offer;
use App\Models\Payout;
use App\Models\Redemption;
use App\Models\Share;
use App\Models\User;
use App\Notifications\InfluencerClaimRejected;
use App\Notifications\NewFollower;
use App\Notifications\PayoutPaid;
use App\Notifications\RedemptionConfirmed;
use App\Notifications\ShareFailed;
use App\Notifications\SharePublished;
use App\Notifications\ShareReviewNeeded;
use Illuminate\Support\Facades\Lang;

/**
 * Notification COPY: what a notification says, and in whose language.
 *
 * Every string used to be a literal inside its class, which meant the language
 * was whatever the author happened to type — the pipeline notifications went out
 * in Spanish, the social and money ones in English, and neither had anything to
 * do with the recipient. Inside a Spanish app the center listed both, mixed.
 *
 * These pin the three things that made that possible:
 *  - copy resolves through the translator, keyed off `data.type`;
 *  - the locale used is the RECIPIENT's, not the app default;
 *  - both language files carry the same keys, so no language can be half-done.
 */

/** Render one notification's stored payload for a recipient. */
function payloadFor(User $recipient, object $notification): array
{
    // `toDatabase()` is a pure payload builder, so this asserts the contract of
    // each class without dispatching anything through the queue.
    return $notification->toDatabase($recipient);
}

/** A user whose account language is `$locale`. */
function userSpeaking(string $locale): User
{
    return User::factory()->create(['locale' => $locale]);
}

/**
 * Run `$fn` with the app locale switched to `$user`'s preference, exactly as
 * Laravel's NotificationSender does around a HasLocalePreference notifiable —
 * so these assertions exercise the same path real delivery takes.
 */
function withLocaleOf(User $user, Closure $fn): mixed
{
    $original = app()->getLocale();

    try {
        app()->setLocale($user->preferredLocale());

        return $fn();
    } finally {
        app()->setLocale($original);
    }
}

it('keeps the same keys in every language file', function () {
    // A key present in one file and missing from the other does not fall back —
    // it renders the dotted key path ("notifications.wallet.payout.title") to
    // whoever is unlucky enough to speak that language.
    $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = array_merge($keys, is_array($value) ? $flatten($value, $path) : [$path]);
        }

        return $keys;
    };

    $es = $flatten(Lang::get('notifications', [], 'es'));
    $en = $flatten(Lang::get('notifications', [], 'en'));

    sort($es);
    sort($en);

    expect($es)->not->toBeEmpty()->and($es)->toBe($en);
});

it('writes every notification in the recipient language, not the app default', function () {
    // The app default is `en` (config/app.php), so a Spanish result here can
    // only have come from the recipient's own preference.
    $spanish = userSpeaking('es');
    $english = userSpeaking('en');

    $share = Share::factory()->create();

    $cases = [
        [new ShareReviewNeeded($share), 'Revisá tu lugar', 'Check your place'],
        [new ShareFailed($share), 'No pudimos procesar tu enlace', "We couldn't process your link"],
        [new NewFollower(User::factory()->create(['username' => 'ana'])), 'Nuevo seguidor', 'New follower'],
        [new InfluencerClaimRejected(Influencer::factory()->create()), 'Reclamo no aprobado', 'Claim not approved'],
        [new PayoutPaid(Payout::factory()->create()), 'Pago enviado', 'Payout sent'],
    ];

    foreach ($cases as [$notification, $esTitle, $enTitle]) {
        // Laravel switches the locale around a queued notification for a
        // HasLocalePreference notifiable; do the same here so this exercises the
        // same path delivery does.
        expect(withLocaleOf($spanish, fn () => payloadFor($spanish, $notification)['title']))->toBe($esTitle);
        expect(withLocaleOf($english, fn () => payloadFor($english, $notification)['title']))->toBe($enTitle);
    }
});

it('interpolates the place name and keeps a whole-sentence fallback without one', function () {
    $user = userSpeaking('es');
    $share = Share::factory()->create();

    // No published place on this share, so the named variant cannot apply.
    $withoutPlace = withLocaleOf($user, fn () => payloadFor($user, new SharePublished($share)));

    expect($withoutPlace['body'])->toBe('Tu lugar ya está en el mapa.')
        // A separate string, NOT the named one with an empty slot — that would
        // read "ya está en tu mapa." with a dangling subject.
        ->and($withoutPlace['body'])->not->toContain('  ')
        ->and($withoutPlace['place_name'])->toBeNull();
});

it('stores the interpolation params the center needs to re-render each type', function () {
    /*
     * The center renders its OWN copy from `type` + these params, which is what
     * lets a row written months ago in Spanish appear in English after the user
     * flips the language toggle. A class that stores only a finished sentence
     * freezes that row's language forever.
     */
    $user = userSpeaking('es');

    $follower = User::factory()->create(['username' => 'ana']);
    $influencer = Influencer::factory()->create(['handle' => 'chef']);
    $payout = Payout::factory()->create(['amount' => 4500, 'currency' => 'EUR']);

    expect(payloadFor($user, new NewFollower($follower)))
        ->toHaveKey('follower_username', 'ana');

    expect(payloadFor($user, new InfluencerClaimRejected($influencer)))
        ->toHaveKey('influencer_handle', 'chef');

    // Minor units and a code — never the formatted string. The client formats
    // money with the user's own currency setting.
    expect(payloadFor($user, new PayoutPaid($payout)))
        ->toHaveKey('amount_minor', 4500)
        ->toHaveKey('currency', 'EUR');
});

/**
 * The deep-link half of the contract.
 *
 * `url` is handed straight to the mobile router by BOTH the center row and the
 * push tap handler, so a path with no route behind it dead-ends on the
 * unmatched-route screen — invisible to every test that only checks the string
 * is non-empty, which is how `/redemptions/{id}` and the plural
 * `/influencers/{id}` both shipped. The mobile suite pins the other half: that
 * these patterns resolve to real route files.
 */
it('deep-links to paths that exist in the mobile router', function () {
    $user = userSpeaking('es');

    $offer = Offer::factory()->create();
    $redemption = Redemption::factory()->for($offer)->redeemed()->create();
    $influencer = Influencer::factory()->create();

    $redemptionUrl = payloadFor($user, new RedemptionConfirmed($redemption))['url'];
    $claimUrl = payloadFor($user, new InfluencerClaimRejected($influencer))['url'];

    // `/offers/{id}/redeem` — the diner's own code screen (T-047), opened ON
    // this redemption rather than issuing a new code. NOT `/redemptions/{id}`,
    // which is a route the app has never had.
    expect($redemptionUrl)->toBe('/offers/'.$offer->id.'/redeem?redemptionId='.$redemption->id);

    // SINGULAR — the segment is `app/influencer/[id]/index.tsx`.
    expect($claimUrl)->toBe('/influencer/'.$influencer->id)
        ->and($claimUrl)->not->toStartWith('/influencers/');
});
