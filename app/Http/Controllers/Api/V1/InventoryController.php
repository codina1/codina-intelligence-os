<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Identity\Services\AuthorizationService;
use App\Normalization\Services\InventoryQueryService;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryQueryService $inventory,
        private readonly AuthorizationService $authorization,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
        ]);

        return ApiResponse::success($this->inventory->summary($user, $validated));
    }

    public function entities(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'content_type' => ['nullable', 'string', 'max:64'],
            'data_source_id' => ['nullable', 'string'],
            'normalized_status' => ['nullable', 'string', 'max:32'],
            'sort' => ['nullable', 'string', 'max:32'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->inventory->listEntities(
            $user,
            $validated,
            (int) ($validated['per_page'] ?? 15),
        );

        return ApiResponse::paginated(
            $paginator,
            fn ($entity) => $this->inventory->serializeEntity($entity)
        );
    }

    public function showEntity(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $entity = $this->inventory->findEntity($user, $id);

        return ApiResponse::success($this->inventory->serializeEntity($entity));
    }

    public function entityVersions(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->inventory->listVersions(
            $user,
            $id,
            (int) ($validated['per_page'] ?? 15),
        );

        return ApiResponse::paginated(
            $paginator,
            fn ($version) => $this->inventory->serializeVersion($version)
        );
    }

    public function metrics(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'provider' => ['nullable', 'string', 'max:64'],
            'metric_key' => ['nullable', 'string', 'max:128'],
            'data_source_id' => ['nullable', 'string'],
            'sort' => ['nullable', 'string', 'max:32'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->inventory->listMetrics(
            $user,
            $validated,
            (int) ($validated['per_page'] ?? 15),
        );

        return ApiResponse::paginated(
            $paginator,
            fn ($metric) => $this->inventory->serializeMetric($metric)
        );
    }

    public function dataQuality(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
        ]);

        return ApiResponse::success($this->inventory->dataQuality($user, $validated));
    }
}
