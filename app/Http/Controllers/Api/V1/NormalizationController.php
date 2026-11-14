<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Identity\Services\AuthorizationService;
use App\Normalization\Services\NormalizationOrchestrator;
use App\Shared\Support\ApiResponse;
use App\Shared\Support\IdempotencyGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NormalizationController extends Controller
{
    public function __construct(
        private readonly NormalizationOrchestrator $normalization,
        private readonly AuthorizationService $authorization,
        private readonly IdempotencyGuard $idempotency,
    ) {}

    public function start(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());

        return $this->idempotency->handle($request, $user, function () use ($user, $id) {
            $run = $this->normalization->start($user, $id);

            return ApiResponse::success($this->normalization->serializeRun($run), status: 202);
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->authorization->assertAuthenticated($request->user());
        $run = $this->normalization->findRunForView($user, $id);

        return ApiResponse::success($this->normalization->serializeRun($run));
    }
}
