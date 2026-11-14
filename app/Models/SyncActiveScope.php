<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncActiveScope extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'data_source_id',
        'entity_type',
        'scope_key',
        'sync_run_id',
        'lease_owner',
        'generation',
        'lease_expires_at',
        'heartbeat_at',
        'cancel_requested_at',
        'cancel_requested_by',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }
}
