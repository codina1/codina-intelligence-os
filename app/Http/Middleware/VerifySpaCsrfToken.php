<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;

/**
 * SPA-compatible CSRF verification for session-authenticated API routes.
 *
 * Unlike Laravel's default PreventRequestForgery behavior, this middleware
 * does not skip verification while PHPUnit is running, so CSRF can be tested.
 * Clients must obtain an XSRF-TOKEN cookie via GET /api/v1/auth/csrf-cookie
 * and send it back as X-XSRF-TOKEN (or X-CSRF-TOKEN / _token).
 */
class VerifySpaCsrfToken extends Middleware
{
    /**
     * @var array<int, string>
     */
    protected $except = [
        // Intentionally empty: login and other mutating API routes require CSRF.
    ];

    protected function runningUnitTests(): bool
    {
        return false;
    }
}
