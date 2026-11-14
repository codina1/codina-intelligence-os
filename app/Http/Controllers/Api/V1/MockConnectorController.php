<?php

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Registry\ConnectorRegistry;
use App\Http\Controllers\Controller;
use App\Identity\Services\AuthorizationService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MockConnectorController extends Controller
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        if (! $user->isPlatformAdmin()) {
            $tenantIds = $user->accessibleTenantIds();
            $allowed = false;
            foreach ($tenantIds as $tenantId) {
                if ($user->hasPermissionInTenant('connectors.view', (int) $tenantId)) {
                    $allowed = true;
                    break;
                }
            }

            if (! $allowed) {
                $this->authorization->assertPermission($user, 'connectors.view', $tenantIds[0] ?? 0);
            }
        }

        return ApiResponse::success($this->registry->summarize());
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $this->authorization->assertAuthenticated($request->user());
        $connector = $this->registry->get($key);

        $scenario = (string) $request->query('scenario', 'success');
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 10);

        if (in_array($scenario, ['invalid_payload', 'rate_limit', 'unauthorized', 'temporary_failure', 'repeated_sync'], true)) {
            $result = $connector->simulateError($scenario);

            return ApiResponse::error(
                $result['error']['code'],
                $result['error']['message'],
                (int) ($result['meta']['http_status'] ?? 400),
                [],
                $result['meta']
            );
        }

        $result = $connector->fetch([
            'scenario' => $scenario,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        return ApiResponse::success($result['data'], $result['meta']);
    }
}
