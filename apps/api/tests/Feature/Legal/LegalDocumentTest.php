<?php

declare(strict_types=1);

/**
 * The public privacy policy and terms of service (T-054).
 *
 * These pages are a store-submission gate, so the tests are about the clauses a
 * reviewer looks for and the numbers the documents PROMISE — not about markup.
 * Three kinds of assertion here:
 *
 *  1. Reachability and locale, because an unauthenticated reviewer opens them
 *     in a browser with no account.
 *  2. The named Apple clauses, because losing one in an edit is a rejection
 *     that nothing else in the suite would notice.
 *  3. Consistency with config: the documents state retention windows and a
 *     deletion grace period as facts. Those are read from config at runtime,
 *     so a changed env default would make the published policy a false
 *     statement with no failing test anywhere. These bind the two together.
 */
use App\Http\Controllers\Legal\LegalDocumentController;

/*
 * A FICTITIOUS identity, deliberately.
 *
 * The whole point of moving these values into config is that the operator's
 * real name and domicile are personal data and stay out of the repository — so
 * putting them back here, in a file that gets committed just like the views
 * did, would defeat the exercise while leaving every test green.
 */
const LEGAL_CONTROLLER = 'Test Operator Ltd';
const LEGAL_DOMICILE = 'Testville, Testland';
const LEGAL_CONTACT = 'legal@example.test';

beforeEach(function () {
    config()->set('legal.controller', LEGAL_CONTROLLER);
    config()->set('legal.domicile', LEGAL_DOMICILE);
    config()->set('legal.contact_email', LEGAL_CONTACT);
});

it('serves both documents in both locales without authentication', function (string $path, string $heading) {
    $this->get($path)
        ->assertOk()
        ->assertSee($heading)
        // Guest, no session, no token — exactly how App Review opens it.
        ->assertHeaderMissing('WWW-Authenticate');
})->with([
    ['/privacy/es', 'Política de privacidad'],
    ['/privacy/en', 'Privacy Policy'],
    ['/terms/es', 'Términos y condiciones'],
    ['/terms/en', 'Terms of Service'],
]);

/*
 * A visitor who states no language preference must be asked for EXPLICITLY.
 *
 * Symfony's `Request::create()` — which Laravel's test client builds on —
 * injects a default `HTTP_ACCEPT_LANGUAGE: en-us,en;q=0.5` server var. So
 * simply omitting the header in a test does not test a header-less request; it
 * quietly tests an English browser, and a Spanish-default assertion written the
 * obvious way fails for a reason that has nothing to do with the code.
 * (Confirmed against the running container: `curl` with no header gets Spanish.)
 */
const NO_LANGUAGE_PREFERENCE = ['Accept-Language' => ''];

it('defaults to Spanish for a visitor who states no preference', function () {
    $this->withHeaders(NO_LANGUAGE_PREFERENCE)
        ->get('/privacy')->assertOk()->assertSee('Política de privacidad');

    $this->withHeaders(NO_LANGUAGE_PREFERENCE)
        ->get('/terms')->assertOk()->assertSee('Términos y condiciones');
});

it('honours Accept-Language, including its q-weights', function () {
    $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')
        ->get('/privacy')->assertOk()->assertSee('Privacy Policy');

    // Region subtags stripped, and the highest weight wins even when it is not
    // listed first — both behaviours come from reusing RequestLocale.
    $this->withHeader('Accept-Language', 'en;q=0.4,es-419;q=0.8')
        ->get('/privacy')->assertOk()->assertSee('Política de privacidad');

    // An unsupported language falls back rather than failing: a legal document
    // that 404s for a French browser is worse than one in Spanish.
    $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->get('/terms')->assertOk()->assertSee('Términos y condiciones');
});

it('does not let the server app locale decide the language of a contract', function () {
    // `APP_LOCALE` is `en` and governs API responses. The published documents
    // must not move to English because of it — this is the assertion that keeps
    // the two settings genuinely independent.
    config()->set('app.locale', 'en');

    $this->withHeaders(NO_LANGUAGE_PREFERENCE)
        ->get('/privacy')->assertOk()->assertSee('Política de privacidad');

    $this->withHeaders(NO_LANGUAGE_PREFERENCE)
        ->get('/terms')->assertOk()->assertSee('Términos y condiciones');
});

it('has a written document for every locale it claims to serve', function () {
    /*
     * The route constraint and the negotiator both come from
     * `RequestLocale::SUPPORTED`, which exists for the API's tag localization —
     * NOT for these documents. Adding `pt` there to localize the app would make
     * `/privacy/pt` a routable URL whose view does not exist: a 500 on the one
     * page a store reviewer is guaranteed to open, from a change made somewhere
     * else entirely, with nothing else in the suite touching it.
     *
     * So the coupling is asserted rather than assumed. This fails the moment a
     * locale is added without the prose to go with it, which is the moment to
     * either write the translation or give these routes their own list.
     */
    foreach (LegalDocumentController::LOCALES as $locale) {
        foreach (['privacy', 'terms'] as $doc) {
            expect(view()->exists("legal.{$doc}.{$locale}"))
                ->toBeTrue("Locale '{$locale}' is routable but legal.{$doc}.{$locale} does not exist");

            $this->get("/{$doc}/{$locale}")->assertOk();
        }
    }
});

it('rejects an unknown pinned locale rather than guessing', function () {
    $this->get('/privacy/fr')->assertNotFound();
    $this->get('/terms/de')->assertNotFound();
});

it('sets the html lang attribute to the locale actually served', function () {
    expect($this->get('/privacy/en')->getContent())->toContain('<html lang="en"');
    expect($this->get('/privacy/es')->getContent())->toContain('<html lang="es"');
});

it('carries the Apple 1.2 zero-tolerance clause in both locales', function () {
    // Apple rejects a UGC app whose EULA does not say this. Asserted by meaning
    // in each language, not by a shared key, because the two texts are written
    // independently and a translation that dropped it would still pass a
    // key-parity check.
    $es = $this->get('/terms/es')->assertOk();
    $es->assertSee('No hay tolerancia alguna con el contenido objetable ni con los usuarios abusivos.', false);
    $es->assertSee('24 horas', false);

    $en = $this->get('/terms/en')->assertOk();
    $en->assertSee('There is no tolerance whatsoever for objectionable content or abusive users.', false);
    $en->assertSee('within 24 hours', false);
});

it('carries the Apple third-party-beneficiary rider a custom EULA requires', function () {
    $this->get('/terms/en')->assertOk()
        ->assertSee('not with Apple', false)
        ->assertSee('third-party beneficiaries', false);

    $this->get('/terms/es')->assertOk()
        ->assertSee('no con Apple', false)
        ->assertSee('terceros beneficiarios', false);
});

it('publishes a reachable moderation and privacy contact on every page', function (string $path) {
    // Apple checks that a report has somewhere to go, and §6.2 of
    // store-readiness.md is not satisfied by copy that names no address.
    $this->get($path)->assertOk()->assertSee(LEGAL_CONTACT, false);
})->with(['/privacy/es', '/privacy/en', '/terms/es', '/terms/en']);

it('names the configured party as the responsible one, in both documents', function (string $path) {
    $this->get($path)->assertOk()
        ->assertSee(LEGAL_CONTROLLER, false)
        ->assertSee(LEGAL_DOMICILE, false);
})->with(['/privacy/es', '/privacy/en', '/terms/es', '/terms/en']);

it('publishes nothing at all when the legal identity is not configured', function (string $missing) {
    /*
     * The reason this file exists in its current shape.
     *
     * The operator's name and domicile are personal data belonging to a private
     * individual, and an unconfigured deployment has no business publishing
     * them — nor publishing a privacy policy that names no data controller,
     * which is not a rougher draft of one but an invalid one.
     *
     * So an incomplete identity must serve NOTHING, and it must do so loudly.
     * Parameterised over each field separately because a guard that only checks
     * the first value it reads passes a test that clears all three.
     */
    config()->set("legal.{$missing}", null);

    foreach (['/privacy', '/privacy/es', '/privacy/en', '/terms', '/terms/es', '/terms/en'] as $path) {
        $response = $this->get($path);

        expect($response->getStatusCode())->toBe(503);
        // Not merely "not a 200": assert the withheld values are absent from
        // whatever the error path does render.
        $response->assertDontSee(LEGAL_CONTROLLER, false);
        $response->assertDontSee(LEGAL_DOMICILE, false);
    }
})->with(['controller', 'domicile', 'contact_email']);

it('keeps the identity out of the view sources entirely', function (string $view) {
    /*
     * The guard against quietly undoing all of the above.
     *
     * Config indirection only protects the operator for as long as nobody
     * pastes a literal back in — and a hard-coded name renders perfectly, ships
     * green, and is invisible in review unless someone happens to read that
     * paragraph. So the sources themselves are checked: every document must
     * still go through the variables, and none may carry a literal email.
     *
     * Note this test cannot name what it is looking for — writing the real name
     * here would commit the very string the change exists to keep out of the
     * repository. It asserts the SHAPE instead: variables present, literals
     * absent.
     */
    $source = file_get_contents(resource_path("views/legal/{$view}.blade.php"));

    expect($source)->toContain('{{ $controller }}')
        ->and($source)->toContain('{{ $domicile }}')
        ->and($source)->toContain('{{ $contact }}')
        // Any literal address, not just the current one.
        ->and($source)->not->toMatch('/[\w.+-]+@[\w-]+\.[\w.]+/');
})->with(['privacy/es', 'privacy/en', 'terms/es', 'terms/en']);

it('treats a blank or whitespace-only identity as unconfigured', function () {
    // `LEGAL_CONTROLLER_NAME=` in an env file yields "", not null, and a stray
    // space yields " ". Both are somebody having NOT filled this in, and a
    // truthiness check that accepted " " would publish a policy whose
    // controller is a space.
    config()->set('legal.controller', '   ');

    $this->get('/privacy/es')->assertStatus(503);
});

it('states the same media retention window the pipeline actually enforces', function () {
    $hours = (int) config('media.retention.original_hours');
    $ceiling = (int) config('media.retention.in_flight_ceiling_hours');

    $this->get('/privacy/es')->assertOk()
        ->assertSee("{$hours} horas", false)
        ->assertSee("{$ceiling} horas", false);

    $this->get('/privacy/en')->assertOk()
        ->assertSee("{$hours} hours", false)
        ->assertSee("{$ceiling} hours", false);
});

it('states the same deletion grace period the purger actually waits', function () {
    $days = (int) config('gdpr.purge_grace_days');

    $this->get('/privacy/es')->assertOk()->assertSee("{$days} días", false);
    $this->get('/privacy/en')->assertOk()->assertSee("{$days} days", false);
});

it('states the same export retention and link lifetime the exporter uses', function () {
    $retention = (int) config('gdpr.export_retention_days');
    $ttl = (int) config('gdpr.export_url_ttl_hours');
    $oembed = (int) config('media.retention.oembed_days');

    $en = $this->get('/privacy/en')->assertOk();
    $en->assertSee("{$retention} days", false);
    $en->assertSee("{$ttl} hours", false);
    $en->assertSee("{$oembed} days", false);

    // Spanish too — and it is the more important of the two, being the default
    // locale of these pages. English-only assertions here meant a Spanish-only
    // edit could drop one of these numbers and pass the whole suite.
    $es = $this->get('/privacy/es')->assertOk();
    $es->assertSee("{$retention} días", false);
    $es->assertSee("{$ttl} horas", false);
    $es->assertSee("{$oembed} días", false);
});

it('discloses that analysis can leave our infrastructure for OpenRouter', function () {
    // The single most consequential disclosure in the policy: frames and a
    // transcript of someone's shared video going to a third party. If a future
    // edit tidies this away the policy becomes untrue, not merely vaguer.
    $this->get('/privacy/en')->assertOk()
        ->assertSee('OpenRouter', false)
        ->assertSee('leave our servers', false);

    $this->get('/privacy/es')->assertOk()
        ->assertSee('OpenRouter', false)
        ->assertSee('salen de nuestros servidores', false);
});

it('answers the tracking question both store questionnaires ask', function () {
    // "Used for tracking" = No is what decides whether App Tracking
    // Transparency applies; the policy has to say it out loud.
    $this->get('/privacy/en')->assertOk()
        ->assertSee('no advertising tracking', false)
        ->assertSee('no data shared with data brokers', false);

    $this->get('/privacy/es')->assertOk()
        ->assertSee('no hace seguimiento publicitario', false)
        ->assertSee('ningún dato compartido con intermediarios de datos', false);
});

it('keeps the legal-basis table a real table, in a reachable scroll region', function (string $path) {
    // The table is the one place the policy encodes a RELATIONSHIP rather than
    // a sentence — purpose ↔ data ↔ legal basis. Scrolling it by putting
    // `display: block` on the <table>, the obvious way, drops row and column
    // semantics in several screen readers and leaves nine unattached phrases;
    // and an overflow area with nothing focusable in it cannot be scrolled by
    // keyboard at all. Asserted so the obvious way cannot come back.
    $html = $this->get($path)->assertOk()->getContent();

    expect($html)->toMatch('/<div class="table-wrap" tabindex="0" role="region" aria-label="[^"]+">\s*<table>/');
})->with(['/privacy/es', '/privacy/en']);

it('renders the effective date in a human and a machine readable form', function () {
    $this->get('/privacy/es')->assertOk()
        ->assertSee('<time datetime="2026-08-13">13 de agosto de 2026</time>', false);

    $this->get('/privacy/en')->assertOk()
        ->assertSee('<time datetime="2026-08-13">13 August 2026</time>', false);
});

it('cross-links the two documents within the same locale', function () {
    // Following the footer from the English policy must not land on Spanish
    // terms — that is the kind of thing only a click ever catches.
    expect($this->get('/privacy/en')->getContent())->toContain('/terms/en');
    expect($this->get('/terms/en')->getContent())->toContain('/privacy/en');
    expect($this->get('/privacy/es')->getContent())->toContain('/terms/es');
    expect($this->get('/terms/es')->getContent())->toContain('/privacy/es');
});

it('offers the other locale of the same document', function () {
    expect($this->get('/privacy/es')->getContent())->toContain('/privacy/en');
    expect($this->get('/terms/en')->getContent())->toContain('/terms/es');
});

it('loads no third-party resources', function (string $path) {
    $html = $this->get($path)->assertOk()->getContent();

    // A privacy policy that phones a font CDN in order to render is
    // self-refuting, and it would be the only outbound request on the page.
    //
    // Scoped to the rels that actually FETCH something. `canonical` and
    // `alternate` are absolute by necessity (they are addresses, not
    // resources) and matching them was the first version of this test failing
    // on its own page.
    expect($html)->not->toContain('<script');
    expect($html)->not->toMatch('/<link[^>]+rel="(stylesheet|preload|prefetch|preconnect|dns-prefetch)"/i');
    expect($html)->not->toMatch('/@import\s+url\(/i');
    expect($html)->not->toMatch('/<img[^>]+src="https?:\/\//i');
    expect($html)->not->toMatch('/url\(\s*[\'"]?https?:\/\//i');
})->with(['/privacy/es', '/privacy/en', '/terms/es', '/terms/en']);
