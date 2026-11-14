<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Identity\Services\AuthorizationService;
use App\Shared\Support\ApiResponse;
use App\Tenancy\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $items = $this->tenants->listForUser($user)->map(fn ($tenant) => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
        ])->values();

        return ApiResponse::success($items);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $tenant = $this->tenants->findForUser($user, $id);

        return ApiResponse::success([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'settings' => $tenant->settings ?? [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', 'unique:tenants,slug'],
            'status' => ['nullable', 'in:active,suspended'],
            'settings' => ['nullable', 'array'],
        ]);

        $tenant = $this->tenants->create($user, $data);

        return ApiResponse::success([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
        ], status: 201);
    }
}
