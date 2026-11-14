<?php

namespace App\Normalization\Services;

use App\Models\NormalizationActiveScope;
use App\Models\NormalizationRun;
use App\Normalization\Enums\NormalizationErrorCode;
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
        $this->assertOwnershipLocked($run, $lease);

        $ttl = max(60, (int) config('normalization.lease_ttl_seconds', 300));
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($run, $lease, $ttl, $now) {
            $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $scope->normalization_run_id !== (int) $run->id
                || (string) $scope->lease_owner !== (string) $lease['owner']
                || (int) $scope->generation !== (int) $lease['generation']) {
                throw new DomainException(NormalizationErrorCode::StaleLease->value);
            }

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
     * @param  array{owner: string, generation: int}  $lease
     */
    public function assertOwnershipLocked(NormalizationRun $run, array $lease): void
    {
        $scope = NormalizationActiveScope::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->lockForUpdate()
            ->first();

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
}
