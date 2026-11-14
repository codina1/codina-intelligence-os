<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionBatch extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'data_source_id',
        'sync_run_id',
        'entity_type',
        'scope_key',
        'page_number',
        'cursor_before_json',
        'cursor_after_json',
        'payload_hash',
        'commit_key',
        'status',
        'rows_received',
        'rows_accepted',
        'rows_rejected',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'cursor_before_json' => 'array',
            'cursor_after_json' => 'array',
            'committed_at' => 'datetime',
            'page_number' => 'integer',
            'rows_received' => 'integer',
            'rows_accepted' => 'integer',
            'rows_rejected' => 'integer',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
