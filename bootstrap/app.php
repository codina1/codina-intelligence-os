<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureJsonApi;
use App\Http\Middleware\VerifySpaCsrfToken;
use App\Shared\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA session auth strategy:
        // 1) GET /api/v1/auth/csrf-cookie (sets encrypted XSRF-TOKEN cookie)
        // 2) Send X-XSRF-TOKEN (or X-CSRF-TOKEN) on state-changing requests
        // 3) Session cookie authenticates the first-party SPA
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            VerifySpaCsrfToken::class,
            AssignRequestId::class,
            EnsureJsonApi::class,
        ]);

        $middleware->alias([
            'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('csrf_token_mismatch', 'CSRF token mismatch.', 419);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('unauthenticated', 'Unauthenticated.', 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error(
                    'validation_error',
                    'The given data was invalid.',
                    422,
                    $e->errors()
                );
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    401 => 'unauthenticated',
                    403 => 'forbidden',
                    404 => 'not_found',
                    419 => 'csrf_token_mismatch',
                    429 => 'rate_limited',
                    default => 'http_error',
                };

                $message = $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.';

                if (! config('app.debug') && $status >= 500) {
                    $message = 'Server error.';
                }

                return ApiResponse::error($code, $message, $status);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof AuthenticationException
                || $e instanceof ValidationException
                || $e instanceof HttpExceptionInterface
                || $e instanceof TokenMismatchException) {
                return null;
            }

            if ($e instanceof \App\Tenancy\Exceptions\TenantContextRequiredException) {
                return ApiResponse::error('tenant_context_required', $e->getMessage(), 403);
            }

            $message = config('app.debug') ? $e->getMessage() : 'Server error.';

            return ApiResponse::error('server_error', $message, 500);
        });
    })->create();
