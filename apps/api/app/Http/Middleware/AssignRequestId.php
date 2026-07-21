<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiExceptionRenderer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mints one correlation id per request (T-092) and threads it everywhere a log
 * or error needs to be cross-referenced:
 *
 *  - `$request->attributes` → {@see ApiExceptionRenderer} reuses
 *    it for the error envelope's `request_id` instead of minting a fresh one.
 *  - {@see Context} → automatically added to every log record for this request
 *    AND serialized into any queued job dispatched during it, so the async
 *    pipeline's stage logs share the request's id (Laravel hydrates the Context
 *    back on the worker).
 *  - `X-Request-Id` response header → lets a client/proxy quote the id in a bug
 *    report.
 *
 * Generated server-side (never trusted from the client) so it can't be spoofed
 * or used to forge log lines. Registered early in the API middleware group.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = 'req_'.(string) Str::ulid();

        $request->attributes->set('request_id', $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
