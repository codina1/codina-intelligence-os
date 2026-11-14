<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncAttempt extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'sync_run_id',
        'attempt_number',
        'status',
        'started_at',
        'finished_at',
        'error_code',
        'error_summary',
        'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'attempt_number' => 'integer',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }
}
