<?php

namespace App\Normalization\Services;

use App\Models\NormalizationActiveScope;
use App\Models\NormalizationRun;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Normalization\Enums\NormalizationRunStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NormalizationLeaseManager
{
    public function ensureScopeRow(NormalizationRun $run): NormalizationActiveScope
    {
        $existing = NormalizationActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return NormalizationActiveScope::query()->create([
                'tenant_id' => $run->tenant_id,
                'data_source_id' => $run->data_source_id,
                'normalization_run_id' => null,
                'lease_owner' => null,
                'generation' => 0,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
            ]);
        } catch (QueryException) {
            return NormalizationActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->firstOrFail();
        }
    }

    /**
     * @return array{owner: string, generation: int}
     */
    public function reserveForQueuedRun(NormalizationRun $run): array
    {
        $owner = 'reserved-'.Str::lower(Str::random(12));
        $ttl = max(60, (int) config('normalization.lease_ttl_seconds', 300));
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($run, $owner, $ttl, $now) {
            $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->lockForUpdate()
                ->firstOrFail();

            $busy = $scope->normalization_run_id !== null
                && $scope->lease_expires_at !== null
                && $scope->lease_expires_at->gt($now);

            if ($busy && (int) $scope->normalization_run_id !== (int) $run->id) {
                throw new DomainException(NormalizationErrorCode::ActiveRunConflict->value);
            }

            $generation = ((int) $scope->generation) + 1;

            $scope->forceFill([
                'normalization_run_id' => $run->id,
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

    /**
     * @return array{owner: string, generation: int}
     */
    public function acquire(NormalizationRun $run, ?string $preferredOwner = null): array
    {
        $owner = $preferredOwner ?? ('worker-'.Str::lower(Str::random(16)));
        $ttl = max(60, (int) config('normalization.lease_ttl_seconds', 300));
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($run, $owner, $ttl, $now) {
            $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->lockForUpdate()
                ->first();

            if ($scope === null) {
                $this->ensureScopeRow($run);
                $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
                    ->where('data_source_id', $run->data_source_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $sameRun = (int) $scope->normalization_run_id === (int) $run->id;
            $expired = $scope->lease_expires_at === null || $scope->lease_expires_at->lte($now);
            $idle = $scope->normalization_run_id === null;

            if (! $sameRun && ! $expired && ! $idle) {
                throw new DomainException(NormalizationErrorCode::ActiveRunConflict->value);
            }

            $generation = $sameRun && $scope->lease_owner !== null
                ? (int) $scope->generation
                : ((int) $scope->generation) + 1;

            $scope->forceFill([
                'normalization_run_id' => $run->id,
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

    /**
     * @param  array{owner: string, generation: int}  $lease
     */
    public function heartbeat(NormalizationRun $run, array $lease): void
    {
        $ttl = max(60, (int) config('normalization.lease_ttl_seconds', 300));
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($run, $lease, $ttl, $now) {
            $this->assertOwnershipLocked($run, $lease);

            $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->lockForUpdate()
                ->firstOrFail();

            $scope->forceFill([
                'lease_expires_at' => $now->addSeconds($ttl),
                'heartbeat_at' => $now,
            ])->save();

            $run->forceFill([
                'lease_expires_at' => $now->addSeconds($ttl),
                'heartbeat_at' => $now,
            ])->save();
        });
    }

    /**
     * Non-locking preflight. Prefer assertOwnershipLocked inside write transactions.
     *
     * @param  array{owner: string, generation: int}  $lease
     */
    public function assertOwnership(NormalizationRun $run, array $lease): void
    {
        $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->first();

        $this->validateScopeOwnership($scope, $run, $lease);
    }

    /**
     * Final fencing check. Caller MUST hold an open DB transaction so
     * SELECT … FOR UPDATE is retained until projection writes commit.
     *
     * @param  array{owner: string, generation: int}  $lease
     */
    public function assertOwnershipLocked(NormalizationRun $run, array $lease): NormalizationActiveScope
    {
        $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->lockForUpdate()
            ->first();

        $this->validateScopeOwnership($scope, $run, $lease);

        return $scope;
    }

    /**
     * Guarded terminal update for a worker that believes it still owns the lease.
     * Requires matching owner+generation. Never reverts completed runs.
     * Never releases a lease owned by another worker.
     *
     * @param  array{owner: string, generation: int}  $lease
     * @param  array<string, mixed>  $attributes
     * @return bool true when ownership matched and status was updated
     */
    public function markTerminalIfStillOwner(
        NormalizationRun $run,
        array $lease,
        NormalizationRunStatus $status,
        array $attributes = [],
        bool $release = false,
    ): bool {
        return DB::transaction(function () use ($run, $lease, $status, $attributes, $release) {
            $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->lockForUpdate()
                ->first();

            $fresh = NormalizationRun::query()->withoutTenantIsolation()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return false;
            }

            if ($fresh->status === NormalizationRunStatus::Completed
                || $fresh->status === NormalizationRunStatus::CompletedWithErrors) {
                return false;
            }

            $stillOwns = $scope !== null
                && (int) $scope->normalization_run_id === (int) $run->id
                && (string) $scope->lease_owner === (string) $lease['owner']
                && (int) $scope->generation === (int) $lease['generation'];

            if (! $stillOwns) {
                return false;
            }

            $fresh->forceFill(array_merge($attributes, [
                'status' => $status,
                'finished_at' => $attributes['finished_at'] ?? CarbonImmutable::now(),
            ]))->save();

            if ($release) {
                $scope->forceFill([
                    'normalization_run_id' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                ])->save();
            }

            return true;
        });
    }

    /**
     * Stale-worker abort: never release another worker's lease, never mutate a
     * newer owner's run, never revert completed. Marks THIS run failed when
     * ownership has transferred; audits are the caller's responsibility.
     *
     * @param  array{owner: string, generation: int}  $lease
     * @param  array<string, mixed>  $attributes
     * @return bool whether THIS run was updated to a terminal failed status
     */
    public function applyStaleWorkerAbort(
        NormalizationRun $run,
        array $lease,
        array $attributes = [],
    ): bool {
        return DB::transaction(function () use ($run, $lease, $attributes) {
            $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->lockForUpdate()
                ->first();

            $fresh = NormalizationRun::query()->withoutTenantIsolation()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if ($fresh === null) {
                return false;
            }

            if ($fresh->status === NormalizationRunStatus::Completed
                || $fresh->status === NormalizationRunStatus::CompletedWithErrors) {
                return false;
            }

            $stillOwns = $scope !== null
                && (int) $scope->normalization_run_id === (int) $run->id
                && (string) $scope->lease_owner === (string) $lease['owner']
                && (int) $scope->generation === (int) $lease['generation'];

            if ($stillOwns) {
                // Lost mid-check race recovered: fail and clear own lease only.
                $fresh->forceFill(array_merge($attributes, [
                    'status' => NormalizationRunStatus::Failed,
                    'finished_at' => $attributes['finished_at'] ?? CarbonImmutable::now(),
                    'last_error_code' => NormalizationErrorCode::StaleLease->value,
                ]))->save();

                $scope->forceFill([
                    'normalization_run_id' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                ])->save();

                return true;
            }

            // Ownership transferred: do not touch the active scope / newer run.
            if ($fresh->status === NormalizationRunStatus::Failed) {
                return false;
            }

            $fresh->forceFill(array_merge($attributes, [
                'status' => NormalizationRunStatus::Failed,
                'finished_at' => $attributes['finished_at'] ?? CarbonImmutable::now(),
                'last_error_code' => NormalizationErrorCode::StaleLease->value,
            ]))->save();

            return true;
        });
    }

    /**
     * @param  array{owner: string, generation: int}|null  $lease
     */
    public function release(NormalizationRun $run, ?array $lease = null): void
    {
        DB::transaction(function () use ($run, $lease) {
            $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->lockForUpdate()
                ->first();

            if ($scope === null) {
                return;
            }

            if ($lease !== null) {
                if ((int) $scope->normalization_run_id !== (int) $run->id
                    || (string) $scope->lease_owner !== (string) $lease['owner']
                    || (int) $scope->generation !== (int) $lease['generation']) {
                    return;
                }
            } elseif ((int) $scope->normalization_run_id !== (int) $run->id) {
                return;
            }

            $scope->forceFill([
                'normalization_run_id' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
            ])->save();
        });
    }

    public function expireLeaseForTest(NormalizationRun $run): void
    {
        $past = CarbonImmutable::now()->subMinutes(10);

        NormalizationActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('normalization_run_id', $run->id)
            ->update([
                'lease_expires_at' => $past,
                'heartbeat_at' => $past,
            ]);

        $run->forceFill([
            'lease_expires_at' => $past,
            'heartbeat_at' => $past,
        ])->save();
    }

    /**
     * @param  array{owner: string, generation: int}  $lease
     */
    private function validateScopeOwnership(
        ?NormalizationActiveScope $scope,
        NormalizationRun $run,
        array $lease,
    ): void {
        if ($scope === null
            || (int) $scope->normalization_run_id !== (int) $run->id
            || (string) $scope->lease_owner !== (string) $lease['owner']
            || (int) $scope->generation !== (int) $lease['generation']) {
            throw new DomainException(NormalizationErrorCode::StaleLease->value);
        }

        if ($scope->lease_expires_at !== null && $scope->lease_expires_at->lte(CarbonImmutable::now())) {
            throw new DomainException(NormalizationErrorCode::StaleLease->value);
        }
    }
}
