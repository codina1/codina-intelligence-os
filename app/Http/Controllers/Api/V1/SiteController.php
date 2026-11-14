<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Identity\Services\AuthorizationService;
use App\Shared\Support\ApiResponse;
use App\Shared\Support\IdempotencyGuard;
use App\Sites\Services\SiteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function __construct(
        private readonly SiteService $sites,
        private readonly AuthorizationService $authorization,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->sites->listForUser(
            $user,
            isset($validated['tenant_id']) ? (int) $validated['tenant_id'] : null,
            (int) ($validated['per_page'] ?? 15),
        );

        return ApiResponse::paginated($paginator, fn ($site) => [
            'id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'name' => $site->name,
            'slug' => $site->slug,
            'primary_url' => $site->primary_url,
            'status' => $site->status,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $site = $this->sites->findForUser($user, $id);

        return ApiResponse::success([
            'id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'name' => $site->name,
            'slug' => $site->slug,
            'primary_url' => $site->primary_url,
            'status' => $site->status,
            'settings' => $site->settings ?? [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'primary_url' => ['required', 'url', 'max:2048'],
            'status' => ['nullable', 'in:active,inactive'],
            'settings' => ['nullable', 'array'],
        ]);

        $request->attributes->set('tenant_id', (int) $data['tenant_id']);

        return $this->idempotency->handle($request, $user, function () use ($user, $data) {
            $site = $this->sites->create($user, $data);

            return ApiResponse::success([
                'id' => $site->id,
                'tenant_id' => $site->tenant_id,
                'name' => $site->name,
                'slug' => $site->slug,
                'primary_url' => $site->primary_url,
                'status' => $site->status,
            ], status: 201);
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'alpha_dash'],
            'primary_url' => ['sometimes', 'url', 'max:2048'],
            'status' => ['sometimes', 'in:active,inactive'],
            'settings' => ['sometimes', 'array'],
            'tenant_id' => ['sometimes', 'integer'],
        ]);

        $site = $this->sites->update($user, $id, $data);

        return ApiResponse::success([
            'id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'name' => $site->name,
            'slug' => $site->slug,
            'primary_url' => $site->primary_url,
            'status' => $site->status,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $this->sites->delete($user, $id);

        return ApiResponse::success(null, status: 200);
    }
}
