<?php

namespace App\Synchronization\Services;

use App\Models\SyncActiveScope;
use App\Models\SyncRun;
use App\Synchronization\Enums\SyncErrorCode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncLeaseManager
{
    public function ensureScopeRow(SyncRun $run): SyncActiveScope
    {
        $existing = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return SyncActiveScope::query()->create([
                'tenant_id' => $run->tenant_id,
                'data_source_id' => $run->data_source_id,
                'entity_type' => $run->entity_type,
                'scope_key' => $run->scope_key,
                'sync_run_id' => null,
                'lease_owner' => null,
                'generation' => 0,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
                'cancel_requested_at' => null,
                'cancel_requested_by' => null,
            ]);
        } catch (QueryException) {
            return SyncActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->where('entity_type', $run->entity_type)
                ->where('scope_key', $run->scope_key)
                ->firstOrFail();
        }
    }

    /**
     * Reserve exclusive scope ownership for a newly queued run (prevents dual queue).
     * Clears any leftover cancellation from a prior owner.
     *
     * @return array{owner: string, generation: int}
     */
    public function reserveForQueuedRun(SyncRun $run): array
    {
        $owner = 'reserved-'.Str::lower(Str::random(12));
        $ttl = max(60, (int) config('sync.lease_ttl_seconds', 300));
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($run, $owner, $ttl, $now) {
            $scope = SyncActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->where('entity_type', $run->entity_type)
                ->where('scope_key', $run->scope_key)
                ->lockForUpdate()
                ->firstOrFail();

            $busy = $scope->sync_run_id !== null
                && $scope->lease_expires_at !== null
                && $scope->lease_expires_at->gt($now);

            if ($busy && (int) $scope->sync_run_id !== (int) $run->id) {
                throw new DomainException(SyncErrorCode::ActiveRunConflict->value);
            }

            $generation = ((int) $scope->generation) + 1;

            // Ownership transfer: clear prior cancellation so it cannot poison this run.
            $scope->forceFill([
                'sync_run_id' => $run->id,
                'lease_owner' => $owner,
                'generation' => $generation,
                'lease_expires_at' => $now->addSeconds($ttl),
                'heartbeat_at' => $now,
                'cancel_requested_at' => null,
                'cancel_requested_by' => null,
            ])->save();

            $run->forceFill([
                'lease_owner' => $owner,
                'generation' => $generation,
                'lease_expires_at' => $now->addSeconds($ttl),
                'heartbeat_at' => $now,
            ])->save();

            return ['owner' => $owner, 'generation' => $generation];
        });
    }

    /**
     * Acquire exclusive ownership for a run using atomic compare-and-swap semantics.
     *
     * @return array{owner: string, generation: int}
     */
    public function acquire(SyncRun $run, ?string $preferredOwner = null): array
    {
        $owner = $preferredOwner ?? ('worker-'.Str::lower(Str::random(16)));
        $ttl = max(60, (int) config('sync.lease_ttl_seconds', 300));
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($run, $owner, $ttl, $now) {
            $scope = SyncActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->where('entity_type', $run->entity_type)
                ->where('scope_key', $run->scope_key)
                ->lockForUpdate()
                ->first();

            if ($scope === null) {
                $scope = $this->ensureScopeRow($run);
                $scope = SyncActiveScope::query()->withoutTenantIsolation()
                    ->whereKey($scope->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $leaseExpired = $scope->lease_expires_at === null
                || $scope->lease_expires_at->lte($now);
            $ownedByThisRun = $scope->sync_run_id === $run->id
                && $scope->lease_owner !== null;

            if (! $ownedByThisRun && ! $leaseExpired && $scope->sync_run_id !== null) {
                throw new DomainException(SyncErrorCode::ActiveRunConflict->value);
            }

            // Same-run cancel must survive re-acquire; foreign cancel must not.
            $clearCancel = ! $ownedByThisRun;

            if ($ownedByThisRun && $scope->cancel_requested_at !== null) {
                throw new DomainException(SyncErrorCode::Cancelled->value);
            }

            $generation = $ownedByThisRun
                ? (int) $scope->generation
                : ((int) $scope->generation) + 1;

            $scope->forceFill([
                'sync_run_id' => $run->id,
                'lease_owner' => $owner,
                'generation' => $generation,
                'lease_expires_at' => $now->addSeconds($ttl),
                'heartbeat_at' => $now,
                'cancel_requested_at' => $clearCancel ? null : $scope->cancel_requested_at,
                'cancel_requested_by' => $clearCancel ? null : $scope->cancel_requested_by,
            ])->save();

            $run->forceFill([
                'lease_owner' => $owner,
                'generation' => $generation,
                'lease_expires_at' => $now->addSeconds($ttl),
                'heartbeat_at' => $now,
            ])->save();

            return ['owner' => $owner, 'generation' => $generation];
        });
    }

    public function heartbeat(SyncRun $run, string $owner, int $generation): bool
    {
        $ttl = max(60, (int) config('sync.lease_ttl_seconds', 300));
        $now = CarbonImmutable::now();
        $expires = $now->addSeconds($ttl);

        return DB::transaction(function () use ($run, $owner, $generation, $now, $expires) {
            $scope = SyncActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->where('entity_type', $run->entity_type)
                ->where('scope_key', $run->scope_key)
                ->where('sync_run_id', $run->id)
                ->where('lease_owner', $owner)
                ->where('generation', $generation)
                ->lockForUpdate()
                ->first();

            if ($scope === null) {
                return false;
            }

            if ($scope->lease_expires_at !== null && $scope->lease_expires_at->lte($now)) {
                return false;
            }

            if ($scope->cancel_requested_at !== null) {
                return false;
            }

            $scope->forceFill([
                'lease_expires_at' => $expires,
                'heartbeat_at' => $now,
            ])->save();

            $run->forceFill([
                'lease_expires_at' => $expires,
                'heartbeat_at' => $now,
            ])->save();

            return true;
        });
    }

    /**
     * Non-locking ownership check (pre-flight). Prefer assertOwnershipLocked inside commits.
     */
    public function assertOwnership(SyncRun $run, string $owner, int $generation): void
    {
        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->first();

        $this->validateScopeOwnership($scope, $run, $owner, $generation);
    }

    /**
     * Final fencing validation with row lock. Call inside the page-commit transaction.
     * Lock order: sync_active_scopes → data_sources → sync_checkpoints → ingestion_*.
     */
    public function assertOwnershipLocked(SyncRun $run, string $owner, int $generation): SyncActiveScope
    {
        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->lockForUpdate()
            ->first();

        $this->validateScopeOwnership($scope, $run, $owner, $generation);

        return $scope;
    }

    public function release(SyncRun $run, string $owner, int $generation): void
    {
        SyncActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->where('sync_run_id', $run->id)
            ->where('lease_owner', $owner)
            ->where('generation', $generation)
            ->update([
                'sync_run_id' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
                // Clear cancellation with ownership so it cannot poison the next run.
                'cancel_requested_at' => null,
                'cancel_requested_by' => null,
                'updated_at' => now(),
            ]);
    }

    public function requestCancel(SyncRun $run, int $userId): void
    {
        DB::transaction(function () use ($run, $userId) {
            $scope = SyncActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->where('entity_type', $run->entity_type)
                ->where('scope_key', $run->scope_key)
                ->where('sync_run_id', $run->id)
                ->lockForUpdate()
                ->first();

            if ($scope === null) {
                return;
            }

            // Never clear an existing cancel for the same active run; only set if absent or refresh timestamp.
            $scope->forceFill([
                'cancel_requested_at' => $scope->cancel_requested_at ?? now(),
                'cancel_requested_by' => $scope->cancel_requested_by ?? $userId,
            ])->save();
        });
    }

    public function isCancelRequested(SyncRun $run): bool
    {
        if ($run->cancelled_at !== null) {
            return true;
        }

        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->where('sync_run_id', $run->id)
            ->first();

        return $scope?->cancel_requested_at !== null;
    }

    /**
     * Force-expire a lease for concurrency tests (not exposed via public API).
     */
    public function expireLeaseForTest(SyncRun $run): void
    {
        $past = CarbonImmutable::now()->subSeconds(5);

        SyncActiveScope::query()->withoutTenantIsolation()
            ->where('sync_run_id', $run->id)
            ->update([
                'lease_expires_at' => $past,
                'updated_at' => now(),
            ]);

        $run->forceFill(['lease_expires_at' => $past])->save();
    }

    private function validateScopeOwnership(?SyncActiveScope $scope, SyncRun $run, string $owner, int $generation): void
    {
        $now = CarbonImmutable::now();

        if (
            $scope === null
            || $scope->sync_run_id !== $run->id
            || $scope->lease_owner !== $owner
            || (int) $scope->generation !== $generation
            || $scope->lease_expires_at === null
            || $scope->lease_expires_at->lte($now)
        ) {
            throw new DomainException(SyncErrorCode::LeaseLost->value);
        }

        if ($scope->cancel_requested_at !== null) {
            throw new DomainException(SyncErrorCode::Cancelled->value);
        }
    }
}
