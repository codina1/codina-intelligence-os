<?php

namespace App\Synchronization\Services;

use App\Models\SyncCheckpoint;
use App\Models\SyncRun;
use App\Synchronization\DTOs\SyncCursor;
use App\Synchronization\Enums\SyncType;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

    public function startingCursor(SyncRun $run, SyncCheckpoint $checkpoint): SyncCursor
    {
        if ($run->sync_type === SyncType::Full) {
            // Resume full sync from last committed page when retrying the same lineage.
            if ($run->retry_count > 0 && $checkpoint->last_completed_page > 0) {
                return SyncCursor::fromArray([
                    'page' => $checkpoint->last_completed_page + 1,
                ]);
            }

            // Fresh full sync resets page progress so re-running from page 1 is not a regression.
            if ((int) $run->retry_count === 0 && (int) $checkpoint->last_completed_page > 0) {
                $checkpoint->forceFill([
                    'cursor_json' => SyncCursor::initial()->toArray(),
                    'last_completed_page' => 0,
                    'version' => ((int) $checkpoint->version) + 1,
                ])->save();
                $checkpoint->refresh();
            }

            return SyncCursor::initial();
        }

        // Incremental: start from committed watermark cursor when present.
        $watermark = $checkpoint->committed_watermark_json;
        if (is_array($watermark) && isset($watermark['cursor'])) {
            return SyncCursor::fromArray($watermark['cursor']);
        }

        if ($checkpoint->last_completed_page > 0 && is_array($checkpoint->cursor_json)) {
            return SyncCursor::fromArray($checkpoint->cursor_json);
        }

        return SyncCursor::initial();
    }

    /**
     * Atomically advance checkpoint. Refuses regression of last_completed_page.
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
        return DB::transaction(function () use ($run, $checkpoint, $pageNumber, $cursorAfter, $watermark, $advanceWatermark) {
            $locked = SyncCheckpoint::query()->withoutTenantIsolation()
                ->whereKey($checkpoint->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($pageNumber < (int) $locked->last_completed_page) {
                throw new DomainException('checkpoint_regression');
            }

            if ($pageNumber === (int) $locked->last_completed_page) {
                // Idempotent re-commit of the same page is allowed; do not bump version.
                return $locked;
            }

            $updates = [
                'cursor_json' => $cursorAfter ?? $locked->cursor_json,
                'last_completed_page' => $pageNumber,
                'last_completed_run_id' => $run->id,
                'version' => ((int) $locked->version) + 1,
                'last_success_at' => now(),
            ];

            if ($advanceWatermark && $watermark !== null) {
                $updates['committed_watermark_json'] = $watermark;
            }

            $locked->forceFill($updates)->save();

            return $locked->fresh();
        });
    }
}
