<?php

namespace App\Shared\Support;

use App\Models\IdempotencyKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class IdempotencyGuard
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public function handle(Request $request, User $user, callable $callback): JsonResponse
    {
        $key = $request->headers->get('Idempotency-Key');

        if ($key === null || $key === '') {
            return $callback();
        }

        $route = $request->method().' '.$request->path();
        $hash = hash('sha256', $request->getContent() ?: '');
        $tenantId = $request->attributes->get('tenant_id');
        $tenantScopeId = $tenantId === null ? 0 : (int) $tenantId;

        $claimed = $this->tryClaim($tenantId, $tenantScopeId, $user->id, $key, $route, $hash);

        if ($claimed === null) {
            return $this->replayOrConflict($tenantScopeId, $user->id, $key, $route, $hash);
        }

        try {
            /** @var JsonResponse $response */
            $response = $callback();

            $claimed->forceFill([
                'status' => self::STATUS_COMPLETED,
                'response_code' => $response->getStatusCode(),
                'response_body' => $response->getData(true),
                'expires_at' => CarbonImmutable::now()->addDay(),
            ])->save();

            return $response;
        } catch (Throwable $e) {
            $claimed->delete();
            throw $e;
        }
    }

    private function tryClaim(
        mixed $tenantId,
        int $tenantScopeId,
        int $userId,
        string $key,
        string $route,
        string $hash,
    ): ?IdempotencyKey {
        try {
            return IdempotencyKey::query()->create([
                'tenant_id' => $tenantId,
                'tenant_scope_id' => $tenantScopeId,
                'user_id' => $userId,
                'key' => $key,
                'route' => $route,
                'request_hash' => $hash,
                'status' => self::STATUS_PENDING,
                'response_code' => null,
                'response_body' => null,
                'expires_at' => CarbonImmutable::now()->addDay(),
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return null;
        }
    }

    private function replayOrConflict(
        int $tenantScopeId,
        int $userId,
        string $key,
        string $route,
        string $hash,
    ): JsonResponse {
        $existing = IdempotencyKey::query()
            ->where('tenant_scope_id', $tenantScopeId)
            ->where('user_id', $userId)
            ->where('key', $key)
            ->where('route', $route)
            ->first();

        if ($existing === null) {
            // Rare race: claim deleted after conflict — ask client to retry.
            return ApiResponse::error(
                'idempotency_race',
                'Idempotency claim raced; retry the request.',
                409
            );
        }

        if ($existing->request_hash !== $hash) {
            return ApiResponse::error(
                'idempotency_conflict',
                'Idempotency key reused with a different payload.',
                409
            );
        }

        if ($existing->status === self::STATUS_PENDING) {
            // Wait briefly for the winning claim to finish (concurrent same payload).
            for ($i = 0; $i < 20; $i++) {
                usleep(50_000);
                $existing->refresh();
                if ($existing->status === self::STATUS_COMPLETED) {
                    break;
                }
            }
        }

        if ($existing->status !== self::STATUS_COMPLETED || $existing->response_body === null) {
            return ApiResponse::error(
                'idempotency_in_progress',
                'A request with this idempotency key is still in progress.',
                409
            );
        }

        return response()->json($existing->response_body, (int) $existing->response_code);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        // SQLSTATE 23000 + MySQL/MariaDB duplicate key 1062
        return $sqlState === '23000' || $driverCode === 1062 || str_contains($e->getMessage(), 'Duplicate');
    }

    /**
     * Low-level helper used by concurrent integration tests.
     */
    public function claimForTest(array $attributes): bool
    {
        try {
            DB::table('idempotency_keys')->insert($attributes);

            return true;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }
    }
}
