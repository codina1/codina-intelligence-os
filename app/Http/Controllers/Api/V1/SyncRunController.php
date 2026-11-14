<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Identity\Services\AuthorizationService;
use App\Shared\Support\ApiResponse;
use App\Shared\Support\IdempotencyGuard;
use App\Synchronization\Services\SyncOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncRunController extends Controller
{
    public function __construct(
        private readonly SyncOrchestrator $sync,
        private readonly AuthorizationService $authorization,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    public function start(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $data = $request->validate([
            'sync_type' => ['required', 'in:full,incremental'],
            'entity_type' => ['required', 'string', 'max:64'],
            'scope_key' => ['nullable', 'string', 'max:128'],
            'options' => ['nullable', 'array'],
        ]);

        return $this->idempotency->handle($request, $user, function () use ($user, $id, $data) {
            $run = $this->sync->start($user, $id, $data);

            return ApiResponse::success($this->sync->serializeRun($run), status: 202);
        });
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'data_source_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:32'],
            'entity_type' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->sync->listRuns(
            $user,
            $validated,
            (int) ($validated['per_page'] ?? 15),
        );

        return ApiResponse::paginated(
            $paginator,
            fn ($run) => $this->sync->serializeRun($run)
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $run = $this->sync->findRunForView($user, $id);

        return ApiResponse::success($this->sync->serializeRun($run));
    }

    public function attempts(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->sync->listAttempts(
            $user,
            $id,
            (int) ($validated['per_page'] ?? 15),
        );

        return ApiResponse::paginated(
            $paginator,
            fn ($attempt) => $this->sync->serializeAttempt($attempt)
        );
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        return $this->idempotency->handle($request, $user, function () use ($user, $id) {
            $run = $this->sync->retry($user, $id);

            return ApiResponse::success($this->sync->serializeRun($run), status: 202);
        });
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $run = $this->sync->cancel($user, $id);

        return ApiResponse::success($this->sync->serializeRun($run));
    }

    public function checkpoints(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->sync->listCheckpoints(
            $user,
            $id,
            (int) ($validated['per_page'] ?? 15),
        );

        return ApiResponse::paginated(
            $paginator,
            fn ($checkpoint) => $this->sync->serializeCheckpoint($checkpoint)
        );
    }

    public function health(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        return ApiResponse::success($this->sync->syncHealth($user, $id));
    }
}
