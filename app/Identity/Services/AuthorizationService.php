<?php

namespace App\Identity\Services;

use App\Models\User;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthorizationService
{
    public function assertAuthenticated(?User $user): User
    {
        if ($user === null) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        return $user;
    }

    public function assertPermission(User $user, string $permission, ?int $tenantId = null): void
    {
        if ($user->isPlatformAdmin()) {
            return;
        }

        if ($tenantId === null) {
            throw new AccessDeniedHttpException('Tenant context required for permission check.');
        }

        if (! $user->hasPermissionInTenant($permission, $tenantId)) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }
    }

    public function assertTenantAccess(User $user, int $tenantId): void
    {
        if ($user->isPlatformAdmin()) {
            return;
        }

        if (! in_array($tenantId, $user->accessibleTenantIds(), true)) {
            throw new AccessDeniedHttpException('Cross-tenant access denied.');
        }
    }

    public function denyAsJson(int $status, string $code, string $message): JsonResponse
    {
        return ApiResponse::error($code, $message, $status);
    }
}
