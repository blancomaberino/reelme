<?php

namespace App\Exceptions;

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
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        [$status, $code, $message, $details] = self::map($e);

        // Preserve headers the exception carries (e.g. Retry-After and
        // X-RateLimit-* on a 429 throttle response).
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'request_id' => self::requestId($request),
            ],
        ], $status, $headers);
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: array<string, mixed>}
     */
    private static function map(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => [422, 'validation_failed', $e->getMessage(), $e->errors()],
            $e instanceof EmailNotVerifiedException => [403, 'email_not_verified', $e->getMessage(), $e->details()],
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
        $existing = $request->attributes->get('request_id');

        return 'req_'.(is_string($existing) && $existing !== '' ? $existing : (string) Str::ulid());
    }
}
