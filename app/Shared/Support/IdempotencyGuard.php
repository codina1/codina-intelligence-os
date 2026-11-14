<?php

namespace App\Shared\Support;

use App\Models\IdempotencyKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyGuard
{
    public function handle(Request $request, User $user, callable $callback): JsonResponse
    {
        $key = $request->headers->get('Idempotency-Key');

        if ($key === null || $key === '') {
            return $callback();
        }

        $route = $request->method().' '.$request->path();
        $hash = hash('sha256', $request->getContent() ?: '');
        $tenantId = $request->attributes->get('tenant_id');

        $existing = IdempotencyKey::query()
            ->where('key', $key)
            ->where('route', $route)
            ->where('user_id', $user->id)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $hash) {
                return ApiResponse::error(
                    'idempotency_conflict',
                    'Idempotency key reused with a different payload.',
                    409
                );
            }

            return response()->json($existing->response_body, (int) $existing->response_code);
        }

        /** @var JsonResponse $response */
        $response = $callback();

        IdempotencyKey::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'key' => $key,
            'route' => $route,
            'request_hash' => $hash,
            'response_code' => $response->getStatusCode(),
            'response_body' => $response->getData(true),
            'expires_at' => CarbonImmutable::now()->addDay(),
        ]);

        return $response;
    }
}
