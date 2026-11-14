<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthSessionController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * SPA bootstrap: ensures the session is started and XSRF-TOKEN cookie is issued.
     */
    public function csrfCookie(Request $request): JsonResponse
    {
        $request->session()->regenerateToken();

        return ApiResponse::success([
            'csrf' => true,
            'message' => 'Send the XSRF-TOKEN cookie value as X-XSRF-TOKEN on mutating requests.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::error('rate_limited', 'Too many login attempts.', 429);
        }

        if (! Auth::attempt($credentials, (bool) $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = $request->user();
        $this->auditLogger->log('auth.login', $user, $user, [
            'email' => $user->email,
        ]);

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_platform_admin' => $user->isPlatformAdmin(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user !== null) {
            $this->auditLogger->log('auth.logout', $user, $user);
        }

        return ApiResponse::success(['logged_out' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Unauthenticated.', 401);
        }

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_platform_admin' => $user->isPlatformAdmin(),
            'tenant_ids' => $user->accessibleTenantIds(),
        ]);
    }
}
