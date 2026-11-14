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

/**
 * Atomic idempotency for Phase 01 local/database operations.
 *
 * Durable statuses:
 * - pending: claim held; work in progress
 * - completed: success; identical payload replays stored response
 * - retryable: DB-local attempt failed and rolled back; same key+hash may retry
 * - failed: terminal ambiguous failure; client must use a new key
 *
 * Limitation: callbacks with external side effects (HTTP, queues that already
 * published, non-transactional stores) are NOT safe to mark retryable after an
 * exception. Phase 01 site create is DB-local and runs inside a transaction.
 * Future phases must register compensating actions or mark failures as `failed`.
 */
class IdempotencyGuard
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_RETRYABLE = 'retryable';

    public const STATUS_FAILED = 'failed';

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
            $reclaimed = $this->tryReclaimForRetry($tenantScopeId, $user->id, $key, $route, $hash);
            if ($reclaimed !== null) {
                return $this->executeClaim($reclaimed, $callback);
            }

            return $this->replayOrConflict($tenantScopeId, $user->id, $key, $route, $hash);
        }

        return $this->executeClaim($claimed, $callback);
    }

    private function executeClaim(IdempotencyKey $claimed, callable $callback): JsonResponse
    {
        try {
            /** @var JsonResponse $response */
            $response = DB::transaction(function () use ($callback, $claimed) {
                $response = $callback();

                $claimed->forceFill([
                    'status' => self::STATUS_COMPLETED,
                    'response_code' => $response->getStatusCode(),
                    'response_body' => $response->getData(true),
                    'expires_at' => CarbonImmutable::now()->addDay(),
                ])->save();

                return $response;
            });

            return $response;
        } catch (Throwable $e) {
            // Never delete the claim after an ambiguous point. Local DB work was
            // rolled back by the transaction above, so Phase 01 marks retryable.
            $this->markRetryable($claimed, $e);
            throw $e;
        }
    }

    private function markRetryable(IdempotencyKey $claimed, Throwable $e): void
    {
        $claimed->refresh();

        if ($claimed->status === self::STATUS_COMPLETED) {
            return;
        }

        $claimed->forceFill([
            'status' => self::STATUS_RETRYABLE,
            'response_code' => null,
            'response_body' => [
                'error' => [
                    'code' => 'retryable_failure',
                    'message' => 'Previous attempt failed after DB rollback; retry with the same Idempotency-Key is allowed for DB-local operations.',
                    'previous' => class_basename($e),
                ],
            ],
            'expires_at' => CarbonImmutable::now()->addDay(),
        ])->save();
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

    private function tryReclaimForRetry(
        int $tenantScopeId,
        int $userId,
        string $key,
        string $route,
        string $hash,
    ): ?IdempotencyKey {
        $existing = IdempotencyKey::query()
            ->where('tenant_scope_id', $tenantScopeId)
            ->where('user_id', $userId)
            ->where('key', $key)
            ->where('route', $route)
            ->first();

        if ($existing === null || $existing->request_hash !== $hash) {
            return null;
        }

        $stalePending = $existing->status === self::STATUS_PENDING
            && $existing->updated_at !== null
            && $existing->updated_at->lt(now()->subSeconds(30));

        if ($existing->status !== self::STATUS_RETRYABLE && ! $stalePending) {
            return null;
        }

        $reclaimed = IdempotencyKey::query()
            ->whereKey($existing->id)
            ->where('request_hash', $hash)
            ->whereIn('status', [self::STATUS_RETRYABLE, self::STATUS_PENDING])
            ->update([
                'status' => self::STATUS_PENDING,
                'response_code' => null,
                'response_body' => null,
                'updated_at' => now(),
            ]);

        if ($reclaimed !== 1) {
            return null;
        }

        return $existing->fresh();
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

        if ($existing->status === self::STATUS_FAILED) {
            return ApiResponse::error(
                'idempotency_failed',
                'A previous attempt with this key failed terminally; use a new Idempotency-Key.',
                409
            );
        }

        if ($existing->status === self::STATUS_PENDING) {
            for ($i = 0; $i < 20; $i++) {
                usleep(50_000);
                $existing->refresh();
                if (in_array($existing->status, [self::STATUS_COMPLETED, self::STATUS_RETRYABLE, self::STATUS_FAILED], true)) {
                    break;
                }
            }
        }

        if ($existing->status === self::STATUS_RETRYABLE) {
            return ApiResponse::error(
                'idempotency_retryable',
                'Previous attempt is retryable; retry with the same Idempotency-Key.',
                409
            );
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

    /**
     * Explicit terminal failure (for future external side-effect paths).
     */
    public function markFailed(IdempotencyKey $claimed, string $message): void
    {
        $claimed->forceFill([
            'status' => self::STATUS_FAILED,
            'response_code' => 409,
            'response_body' => [
                'error' => [
                    'code' => 'idempotency_failed',
                    'message' => $message,
                ],
            ],
            'expires_at' => CarbonImmutable::now()->addDay(),
        ])->save();
    }
}
