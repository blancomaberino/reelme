<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Support\Observability\ErrorReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First in the API stack: every request (and any job it dispatches, and
        // every log it writes) carries one correlation id (T-092).
        $middleware->prependToGroup('api', AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Forward genuine server errors to the error tracker (T-091) with the
        // request_id (T-092) — NOT the expected client errors (validation, auth,
        // 404, other 4xx), which are normal flow and would drown the signal.
        $exceptions->report(function (Throwable $e): void {
            $expected = $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof ModelNotFoundException
                || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500);

            if ($expected) {
                return;
            }

            app(ErrorReporter::class)->capture($e, ['request_id' => Context::get('request_id')]);
        });

        // Every non-2xx API response uses the canonical error envelope.
        $exceptions->render(
            fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request),
        );
    })->create();
