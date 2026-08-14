<?php

declare(strict_types=1);

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Support\RequestLocale;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public privacy policy and terms of service (T-054).
 *
 * These are served from the API app rather than a marketing site because there
 * is no `apps/web` in this monorepo, and both stores require a REACHABLE public
 * URL before a build can be submitted. Serving them here means the documents
 * ship, deploy and get reviewed with the code that describes them — a policy
 * that lives in a CMS drifts from the schema the day someone adds a column.
 *
 * Deliberately unauthenticated and deliberately outside `/api/v1`: an App
 * Review reviewer opens these in a browser with no account, and Apple also
 * requires the privacy policy to be reachable from inside the app itself.
 */
final class LegalDocumentController extends Controller
{
    /**
     * Locales the documents exist in. Spanish is the source of truth — it is the
     * app's default language and the controller's own jurisdiction — and English
     * is provided because App Review reads English.
     *
     * Delegated to {@see RequestLocale::SUPPORTED} rather than restated, so the
     * route constraint and the content negotiation can never disagree about
     * which locales exist.
     *
     * @var list<string>
     */
    public const LOCALES = RequestLocale::SUPPORTED;

    /**
     * Where a visitor with no stated preference lands.
     *
     * Deliberately NOT `config('app.locale')`, which is `en`: that setting picks
     * the language of API responses to a client that did not ask, and this is a
     * legal document published from Uruguay by a Spanish-speaking operator for
     * an app whose own default language is Spanish. Tying the two together
     * would let an unrelated server config change the language a contract is
     * presented in.
     */
    public const DEFAULT_LOCALE = 'es';

    /**
     * Effective dates, per document.
     *
     * Hard-coded rather than derived from file mtime or the deploy date: the
     * date a legal document took effect is a claim about when the terms
     * CHANGED, and a value that moved on every deploy would silently assert a
     * new effective date for text nobody touched. Bump these by hand, in the
     * same commit as the wording.
     */
    private const UPDATED = [
        'privacy' => '2026-08-13',
        'terms' => '2026-08-13',
    ];

    public function privacy(Request $request, ?string $locale = null): View
    {
        return $this->document('privacy', $request, $locale);
    }

    public function terms(Request $request, ?string $locale = null): View
    {
        return $this->document('terms', $request, $locale);
    }

    private function document(string $doc, Request $request, ?string $locale): View
    {
        $locale = $this->resolveLocale($request, $locale);
        $identity = $this->identity();

        return view("legal.{$doc}.{$locale}", $identity + [
            'doc' => $doc,
            'locale' => $locale,
            // Passed in rather than read off this class from the template: the
            // layout renders a language switch, it should not have to know
            // which controller produced it.
            'locales' => self::LOCALES,
            'updatedIso' => self::UPDATED[$doc],
            'updated' => $this->formatDate(self::UPDATED[$doc], $locale),
        ]);
    }

    /**
     * "13 de agosto de 2026" / "13 August 2026".
     *
     * Spelled out rather than numeric because 08-13 and 13-08 are the same
     * string to a machine and opposite dates to a reader, and this one is the
     * date a contract took effect. The ISO value still goes out in the `<time>`
     * attribute for anything parsing the page.
     */
    private function formatDate(string $iso, string $locale): string
    {
        $date = CarbonImmutable::createFromFormat('Y-m-d', $iso);
        $month = $date->locale($locale)->translatedFormat('F');

        return $locale === 'es'
            ? "{$date->day} de {$month} de {$date->year}"
            : "{$date->day} {$month} {$date->year}";
    }

    /**
     * Who the documents name — read from config, never hard-coded.
     *
     * The operator is an individual, so their name and domicile are personal
     * data in their own right; they are not committed to the repository. An
     * unconfigured deployment therefore has nothing legitimate to publish, and
     * this refuses rather than improvising.
     *
     * 503, not a placeholder: the two silent alternatives are publishing a name
     * that was meant to be withheld, or publishing a privacy policy that names
     * no data controller — which is not a rougher draft of a privacy policy, it
     * is an invalid one, and it would sail through every test that only checks
     * for a 200. A missing legal identity is a deployment that is not ready to
     * serve these pages at all.
     *
     * @return array{controller: string, domicile: string, contact: string}
     */
    private function identity(): array
    {
        $controller = trim((string) config('legal.controller'));
        $domicile = trim((string) config('legal.domicile'));
        $contact = trim((string) config('legal.contact_email'));

        abort_if(
            $controller === '' || $domicile === '' || $contact === '',
            503,
            'The legal documents are not configured for publication. Set LEGAL_CONTROLLER_NAME, '
            .'LEGAL_CONTROLLER_DOMICILE and LEGAL_CONTACT_EMAIL.',
        );

        return ['controller' => $controller, 'domicile' => $domicile, 'contact' => $contact];
    }

    /**
     * The pinned path segment wins; otherwise whatever the client actually asked
     * for, via the app's one locale negotiator; otherwise Spanish.
     *
     * `explicit()` and not `resolve()`: the difference is exactly the fallback,
     * and {@see RequestLocale::resolve()} falls back to `config('app.locale')`
     * for the reason described on {@see DEFAULT_LOCALE}. The q-weight and
     * region-subtag handling — the part that actually goes wrong — is shared.
     *
     * A client expressing an unsupported language gets the default rather than a
     * 404: a legal document missing for a French browser is worse than one in
     * the wrong language, and the language switch is at the top of the page.
     */
    private function resolveLocale(Request $request, ?string $locale): string
    {
        if ($locale !== null && in_array($locale, self::LOCALES, true)) {
            return $locale;
        }

        return RequestLocale::explicit($request) ?? self::DEFAULT_LOCALE;
    }
}
