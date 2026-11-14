<?php

namespace App\Normalization\Services;

use App\Models\IngestionRecord;
use App\Models\SyncRun;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Synchronization\Enums\SyncRunStatus;
use Illuminate\Support\Collection;

/**
 * Phase 02C provenance policy: only project staging rows whose latest
 * last_sync_run_id points at a completed SyncRun for the same tenant/source.
 *
 * Limitation: ingestion_records are mutable upserts. After a partial/failed sync
 * overwrites a row, the prior completed payload is not reconstructable from
 * staging alone. Those rows are inadmissible (fail closed) until a later
 * completed sync rewrites them.
 */
class StagingProvenanceGate
{
    /**
     * @return Collection<int, int> completed sync run ids for source
     */
    public function completedRunIds(int $tenantId, int $dataSourceId): Collection
    {
        return SyncRun::query()->withoutTenantIsolation()
            ->where('tenant_id', $tenantId)
            ->where('data_source_id', $dataSourceId)
            ->where('status', SyncRunStatus::Completed->value)
            ->pluck('id');
    }

    public function hasCompletedSync(int $tenantId, int $dataSourceId): bool
    {
        return $this->completedRunIds($tenantId, $dataSourceId)->isNotEmpty();
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $completedRunIds
     * @return array{ok: bool, error_code: ?string, diagnostics: array<string, mixed>}
     */
    public function evaluate(IngestionRecord $record, Collection|array $completedRunIds): array
    {
        $completed = $completedRunIds instanceof Collection
            ? $completedRunIds
            : collect($completedRunIds);

        $lastRunId = $record->last_sync_run_id;

        if ($lastRunId === null) {
            return [
                'ok' => false,
                'error_code' => NormalizationErrorCode::InadmissibleProvenance->value,
                'diagnostics' => [
                    'reason' => 'missing_last_sync_run_id',
                    'policy' => (string) config('normalization.provenance_policy'),
                ],
            ];
        }

        if (! $completed->contains((int) $lastRunId)) {
            $status = SyncRun::query()->withoutTenantIsolation()
                ->whereKey((int) $lastRunId)
                ->value('status');

            return [
                'ok' => false,
                'error_code' => NormalizationErrorCode::InadmissibleProvenance->value,
                'diagnostics' => [
                    'reason' => 'latest_provenance_not_completed',
                    'last_sync_run_id' => (int) $lastRunId,
                    'last_sync_run_status' => $status,
                    'limitation' => 'prior_completed_snapshot_not_reconstructable',
                    'policy' => (string) config('normalization.provenance_policy'),
                ],
            ];
        }

        return [
            'ok' => true,
            'error_code' => null,
            'diagnostics' => [
                'last_sync_run_id' => (int) $lastRunId,
            ],
        ];
    }
}
