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

            $generation = $ownedByThisRun
                ? (int) $scope->generation
                : ((int) $scope->generation) + 1;

            $scope->forceFill([
                'sync_run_id' => $run->id,
                'lease_owner' => $owner,
                'generation' => $generation,
                'lease_expires_at' => $now->addSeconds($ttl),
                'heartbeat_at' => $now,
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

        // Load then save: MariaDB PDO rowCount for UPDATE returns "changed" rows,
        // which is 0 when heartbeat timestamps fall in the same second as acquire.
        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->where('sync_run_id', $run->id)
            ->where('lease_owner', $owner)
            ->where('generation', $generation)
            ->where(function ($q) use ($now) {
                $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '>', $now);
            })
            ->first();

        if ($scope === null) {
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
    }

    public function assertOwnership(SyncRun $run, string $owner, int $generation): void
    {
        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->first();

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
                'updated_at' => now(),
            ]);
    }

    public function requestCancel(SyncRun $run, int $userId): void
    {
        SyncActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->where('sync_run_id', $run->id)
            ->update([
                'cancel_requested_at' => now(),
                'cancel_requested_by' => $userId,
                'updated_at' => now(),
            ]);
    }

    public function isCancelRequested(SyncRun $run): bool
    {
        $scope = SyncActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->where('sync_run_id', $run->id)
            ->first();

        return $scope?->cancel_requested_at !== null || $run->cancelled_at !== null;
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
}
