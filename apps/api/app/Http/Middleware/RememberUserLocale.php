<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RequestLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps `users.locale` in step with the language the app is actually running in.
 *
 * A push is composed in a queued worker, minutes after — and sometimes days
 * after — the request that triggered it, where there is no `Accept-Language` to
 * read. So the recipient's language has to be a stored fact, and this is what
 * stores it: the mobile client already sends `Accept-Language` on every call
 * (ADR-084 #2), so flipping the in-app language toggle updates the column on
 * the very next request, with no new endpoint and nothing for the client to
 * remember to call.
 *
 * Two deliberate properties:
 *
 * - **Writes only on change.** Comparing first turns "a write on every API
 *   request" into "a write per language switch".
 * - **Runs in `terminate()`.** The locale of the NEXT notification is not worth
 *   a millisecond on the current response, and doing it after the response is
 *   sent means a locked row or a slow write can never surface as latency.
 */
class RememberUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        // `explicit`, NOT `resolve`: no header at all is not a request for the
        // app default, and treating it as one would flip a Spanish account to
        // English on the first call from anything that omits the header.
        $locale = RequestLocale::explicit($request);

        if ($locale === null) {
            return;
        }

        /*
         * The "only write on change" test lives in the WHERE, not in PHP.
         *
         * Reading `$user->locale` to decide would compare against a value
         * loaded at the start of the request: two requests from two devices in
         * different languages can both load `es`, the `en` one writes, and the
         * `es` one then skips on its stale copy — leaving the account in the
         * language of the request that finished FIRST. Conditioning the
         * statement keeps the write cheap (no row matches when nothing changed)
         * while letting the later request win, which is the one the user acted
         * on most recently.
         *
         * `update` on a fresh query, not `$user->save()`: the in-memory model
         * may be mid-request-lifecycle with other dirty attributes, and this
         * must persist exactly one column and nothing else.
         */
        User::query()
            ->whereKey($user->getKey())
            ->where('locale', '!=', $locale)
            ->update(['locale' => $locale]);
    }
}
