<?php

namespace App\Http\Controllers\Api\V1;

use App\Connectors\Services\DataSourceService;
use App\Http\Controllers\Controller;
use App\Identity\Services\AuthorizationService;
use App\Shared\Support\ApiResponse;
use App\Shared\Support\IdempotencyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataSourceController extends Controller
{
    public function __construct(
        private readonly DataSourceService $dataSources,
        private readonly AuthorizationService $authorization,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'site' => ['nullable', 'integer'],
            'provider' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:32'],
            'mode' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->dataSources->listForUser(
            $user,
            $validated,
            (int) ($validated['per_page'] ?? 15),
        );

        return ApiResponse::paginated(
            $paginator,
            fn ($source) => $this->dataSources->serialize($source, $user, includeCredentialsMeta: false)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'site_id' => ['nullable', 'integer'],
            'provider' => ['required', 'string', 'max:64'],
            'display_name' => ['required', 'string', 'max:255'],
            // Strict mock-safe rules enforced in ProviderSettingsValidator (not max-length alone).
            'external_resource_id' => ['nullable', 'string'],
            'mode' => ['nullable', 'in:mock'],
            'auth_method' => ['nullable', 'string', 'max:64'],
            'settings' => ['nullable', 'array'],
        ]);

        $request->attributes->set('tenant_id', (int) $data['tenant_id']);

        return $this->idempotency->handle($request, $user, function () use ($user, $data) {
            $source = $this->dataSources->create($user, $data);

            return ApiResponse::success(
                $this->dataSources->serialize($source, $user),
                status: 201
            );
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $source = $this->dataSources->findForUser($user, $id);

        return ApiResponse::success($this->dataSources->serialize($source, $user));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $data = $request->validate([
            'tenant_id' => ['sometimes', 'integer'],
            'site_id' => ['sometimes', 'nullable', 'integer'],
            'provider' => ['sometimes', 'string', 'max:64'],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'external_resource_id' => ['sometimes', 'nullable', 'string'],
            'mode' => ['sometimes', 'in:mock'],
            'auth_method' => ['sometimes', 'string', 'max:64'],
            'settings' => ['sometimes', 'array'],
            // healthy/error are health-check-derived only — not settable via public PATCH.
            'status' => ['sometimes', 'in:draft,configured,disabled'],
            'change_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $source = $this->dataSources->update($user, $id, $data);

        return ApiResponse::success($this->dataSources->serialize($source, $user));
    }

    public function test(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'scenario' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->dataSources->testConnection(
            $user,
            $id,
            $validated['scenario'] ?? null,
        );

        return ApiResponse::success([
            'data_source' => $this->dataSources->serialize($result['source'], $user),
            'check' => [
                'id' => $result['check']->id,
                'check_type' => $result['check']->check_type,
                'status' => $result['check']->status,
                'duration_ms' => $result['check']->duration_ms,
                'error_code' => $result['check']->error_code,
                'result_metadata' => $result['check']->result_metadata,
                'checked_at' => $result['check']->checked_at?->toIso8601String(),
            ],
            'result' => $result['result'],
        ]);
    }

    public function health(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->dataSources->healthHistory(
            $user,
            $id,
            (int) ($validated['per_page'] ?? 15),
        );

        return ApiResponse::paginated($paginator, fn ($check) => [
            'id' => $check->id,
            'check_type' => $check->check_type,
            'status' => $check->status,
            'duration_ms' => $check->duration_ms,
            'error_code' => $check->error_code,
            'result_metadata' => $check->result_metadata,
            'checked_at' => $check->checked_at?->toIso8601String(),
        ]);
    }

    public function capabilities(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $capabilities = $this->dataSources->capabilities($user, $id);

        return ApiResponse::success($capabilities);
    }

    public function disable(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $source = $this->dataSources->disable($user, $id);

        return ApiResponse::success($this->dataSources->serialize($source, $user));
    }
}
