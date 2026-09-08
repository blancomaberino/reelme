<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\ApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every non-2xx API response as the canonical error envelope
 * (03-api-design.md §1): {"error": {code, message, details, request_id}}.
 *
 * Registered from bootstrap/app.php. Returns null for non-API requests so the
 * default framework handler renders them.
 */
class ApiExceptionRenderer
{
    /**
     * Whether this renderer speaks for the given request. Asked, not copied:
     * the report rule in bootstrap/app.php uses the same classification, and a
     * second hand-written `is('api/*')` would silence the wrong surface the
     * first time the prefix moved, with nothing failing.
     */
    public static function handles(?Request $request): bool
    {
        return $request?->is('api/*') ?? false;
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::handles($request)) {
            return null;
        }

        [$status, $code, $message, $details] = self::map($e);

        // Preserve headers the exception carries (e.g. Retry-After and
        // X-RateLimit-* on a 429 throttle response).
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        // Also echo X-Request-Id here (not just in AssignRequestId): a thrown
        // request never reaches the middleware's post-$next header write, so the
        // header would otherwise be missing on exactly the error responses that
        // most need to be cross-referenced (T-092).
        $requestId = self::requestId($request);
        $headers['X-Request-Id'] = $requestId;

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'request_id' => $requestId,
            ],
        ], $status, $headers);
    }

    /**
     * How this exception is CLASSIFIED, expressed as the status an API response
     * would carry — NOT what `render()` returns for a given request, which is
     * null outside `api/*`. Pair it with `handles()` before treating it as
     * policy for a surface this renderer does not serve.
     *
     * Exposed so the report rule can ask one question of the mapping instead of
     * keeping a second list of classes beside it; bootstrap/app.php carries the
     * incident that made that necessary.
     */
    public static function statusFor(Throwable $e): int
    {
        return self::map($e)[0];
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>}
     */
    private static function map(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => [422, 'validation_failed', $e->getMessage(), $e->errors()],
            // One arm for every exception that states its own meaning. Naming
            // the classes here is what let a new one be forgotten and rendered
            // as a 500 — and, once the report rule started asking this mapping,
            // reported as a fault too. The framework arms below stay explicit:
            // they are not ours to make implement anything.
            $e instanceof ApiError => [$e->status(), $e->errorCode(), $e->getMessage(), $e->details()],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Unauthenticated.', []],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'forbidden', 'This action is unauthorized.', []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'not_found', 'Resource not found.', []],
            default => self::mapHttpOrServer($e),
        };
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>}
     */
    private static function mapHttpOrServer(Throwable $e): array
    {
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            if ($status >= 500) {
                return [$status, 'server_error', self::safeServerMessage($e), []];
            }

            $code = match ($status) {
                400 => 'bad_request',
                405 => 'method_not_allowed',
                409 => 'conflict',
                429 => 'rate_limited',
                default => 'http_error',
            };

            return [$status, $code, $e->getMessage() !== '' ? $e->getMessage() : 'Request could not be processed.', []];
        }

        return [500, 'server_error', self::safeServerMessage($e), []];
    }

    private static function safeServerMessage(Throwable $e): string
    {
        return config('app.debug') && $e->getMessage() !== '' ? $e->getMessage() : 'Server error.';
    }

    private static function requestId(Request $request): string
    {
        // Reuse the id AssignRequestId (T-092) set so the envelope, the
        // X-Request-Id header, and this request's logs/jobs all share one value.
        // Fall back to a fresh id only if the middleware never ran (a non-API
        // entrypoint, or an exception thrown before the stack reached it).
        $existing = $request->attributes->get('request_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return 'req_'.(string) Str::ulid();
    }
}
