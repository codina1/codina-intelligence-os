<?php

namespace App\Synchronization\Services;

use App\Models\SyncCheckpoint;
use App\Models\SyncRun;
use App\Synchronization\DTOs\SyncCursor;
use App\Synchronization\Enums\SyncType;
use DomainException;
use Illuminate\Database\QueryException;

class CheckpointManager
{
    public function getOrCreate(SyncRun $run): SyncCheckpoint
    {
        $checkpoint = SyncCheckpoint::query()->withoutTenantIsolation()
            ->where('data_source_id', $run->data_source_id)
            ->where('entity_type', $run->entity_type)
            ->where('scope_key', $run->scope_key)
            ->first();

        if ($checkpoint !== null) {
            return $checkpoint;
        }

        try {
            return SyncCheckpoint::query()->create([
                'tenant_id' => $run->tenant_id,
                'data_source_id' => $run->data_source_id,
                'entity_type' => $run->entity_type,
                'scope_key' => $run->scope_key,
                'cursor_json' => SyncCursor::initial()->toArray(),
                'last_completed_page' => 0,
                'last_completed_run_id' => null,
                'committed_watermark_json' => null,
                'version' => 0,
                'last_success_at' => null,
            ]);
        } catch (QueryException) {
            return SyncCheckpoint::query()->withoutTenantIsolation()
                ->where('data_source_id', $run->data_source_id)
                ->where('entity_type', $run->entity_type)
                ->where('scope_key', $run->scope_key)
                ->firstOrFail();
        }
    }

    /**
     * Committed change-stream watermark only (never a pagination page cursor).
     */
    public function committedSince(SyncCheckpoint $checkpoint): ?string
    {
        $watermark = $checkpoint->committed_watermark_json;
        if (! is_array($watermark)) {
            return null;
        }

        if (isset($watermark['since']) && is_string($watermark['since'])) {
            return $watermark['since'];
        }

        // Legacy remediation: ignore previous cursor-shaped watermarks for pagination.
        return null;
    }

    public function startingCursor(SyncRun $run, SyncCheckpoint $checkpoint): SyncCursor
    {
        $isRetry = $this->isResumeOfSameRun($run, $checkpoint);

        if ($run->sync_type === SyncType::Full) {
            if ($isRetry && $checkpoint->last_completed_page > 0) {
                return SyncCursor::fromArray([
                    'page' => $checkpoint->last_completed_page + 1,
                ]);
            }

            if (! $isRetry && (int) $checkpoint->last_completed_page > 0) {
                $this->resetPageProgress($checkpoint);
            }

            return SyncCursor::initial();
        }

        // Incremental: pagination always starts at page 1 for a new run.
        // Retries resume the run-local page cursor; watermark filter stays separate.
        if ($isRetry && $checkpoint->last_completed_page > 0) {
            return SyncCursor::fromArray([
                'page' => $checkpoint->last_completed_page + 1,
            ]);
        }

        if (! $isRetry && (int) $checkpoint->last_completed_page > 0) {
            $this->resetPageProgress($checkpoint);
        }

        return SyncCursor::initial();
    }

    /**
     * Atomically advance run-local page cursor. Watermark advances only when requested
     * after a fully successful incremental run.
     *
     * @param  array<string, mixed>|null  $cursorAfter
     * @param  array<string, mixed>|null  $watermark
     */
    public function commitPage(
        SyncRun $run,
        SyncCheckpoint $checkpoint,
        int $pageNumber,
        ?array $cursorAfter,
        ?array $watermark = null,
        bool $advanceWatermark = false,
    ): SyncCheckpoint {
        $locked = SyncCheckpoint::query()->withoutTenantIsolation()
            ->whereKey($checkpoint->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($pageNumber < (int) $locked->last_completed_page) {
            throw new DomainException('checkpoint_regression');
        }

        if ($pageNumber === (int) $locked->last_completed_page) {
            // Idempotent re-commit of the same page; do not bump version or watermark.
            return $locked;
        }

        // Pagination cursor only — strip any accidental watermark/page bleed.
        $pageCursor = ['page' => isset($cursorAfter['page']) ? (int) $cursorAfter['page'] : ($pageNumber + 1)];

        $updates = [
            'cursor_json' => $pageCursor,
            'last_completed_page' => $pageNumber,
            'last_completed_run_id' => $run->id,
            'version' => ((int) $locked->version) + 1,
            'last_success_at' => now(),
        ];

        if ($advanceWatermark && $watermark !== null && isset($watermark['since'])) {
            $updates['committed_watermark_json'] = [
                'since' => (string) $watermark['since'],
                'committed_at' => $watermark['committed_at'] ?? now()->toIso8601String(),
                'simulated' => true,
                'last_completed_run_id' => $run->id,
            ];
        }

        $locked->forceFill($updates)->save();

        return $locked->fresh();
    }

    private function isResumeOfSameRun(SyncRun $run, SyncCheckpoint $checkpoint): bool
    {
        if ((int) $run->retry_count > 0) {
            return true;
        }

        return $checkpoint->last_completed_run_id !== null
            && (int) $checkpoint->last_completed_run_id === (int) $run->id
            && (int) $checkpoint->last_completed_page > 0;
    }

    private function resetPageProgress(SyncCheckpoint $checkpoint): void
    {
        $checkpoint->forceFill([
            'cursor_json' => SyncCursor::initial()->toArray(),
            'last_completed_page' => 0,
            'version' => ((int) $checkpoint->version) + 1,
        ])->save();
        $checkpoint->refresh();
    }
}
