<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncCheckpoint extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'data_source_id',
        'entity_type',
        'scope_key',
        'cursor_json',
        'last_completed_page',
        'last_completed_run_id',
        'committed_watermark_json',
        'version',
        'last_success_at',
    ];

    protected function casts(): array
    {
        return [
            'cursor_json' => 'array',
            'committed_watermark_json' => 'array',
            'last_success_at' => 'datetime',
            'last_completed_page' => 'integer',
            'version' => 'integer',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function lastCompletedRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'last_completed_run_id');
    }
}
