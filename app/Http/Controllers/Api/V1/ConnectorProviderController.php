<?php

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Registry\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Identity\Services\AuthorizationService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ConnectorProviderController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $this->assertCanViewConnectors($user);

        return ApiResponse::success($this->providers->summarize());
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $this->assertCanViewConnectors($user);

        if (! $this->providers->has($key)) {
            throw new NotFoundHttpException('Provider not found.');
        }

        return ApiResponse::success($this->providers->get($key)->toArray());
    }

    private function assertCanViewConnectors(\App\Models\User $user): void
    {
        if ($user->isPlatformAdmin()) {
            return;
        }

        $tenantIds = $user->accessibleTenantIds();
        foreach ($tenantIds as $tenantId) {
            if ($user->hasPermissionInTenant('connectors.view', (int) $tenantId)) {
                return;
            }
        }

        $this->authorization->assertPermission($user, 'connectors.view', $tenantIds[0] ?? 0);
    }
}
