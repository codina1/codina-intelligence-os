<?php

namespace App\Models;

use App\Synchronization\Enums\SyncRunStatus;
use App\Synchronization\Enums\SyncType;
use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'data_source_id',
        'entity_type',
        'scope_key',
        'sync_type',
        'status',
        'generation',
        'lease_owner',
        'lease_expires_at',
        'heartbeat_at',
        'started_at',
        'finished_at',
        'rows_received',
        'rows_inserted',
        'rows_updated',
        'rows_unchanged',
        'rows_rejected',
        'rows_duplicate_in_batch',
        'pages_completed',
        'completeness_status',
        'retry_count',
        'last_error_code',
        'last_error_summary',
        'requested_by',
        'cancelled_by',
        'cancelled_at',
        'options_json',
        'is_simulated',
    ];

    protected function casts(): array
    {
        return [
            'sync_type' => SyncType::class,
            'status' => SyncRunStatus::class,
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'options_json' => 'array',
            'is_simulated' => 'boolean',
            'generation' => 'integer',
            'rows_received' => 'integer',
            'rows_inserted' => 'integer',
            'rows_updated' => 'integer',
            'rows_unchanged' => 'integer',
            'rows_rejected' => 'integer',
            'rows_duplicate_in_batch' => 'integer',
            'pages_completed' => 'integer',
            'retry_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SyncAttempt::class);
    }

    public function ingestionBatches(): HasMany
    {
        return $this->hasMany(IngestionBatch::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
