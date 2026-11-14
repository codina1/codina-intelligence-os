<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizationActiveScope extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'data_source_id',
        'normalization_run_id',
        'lease_owner',
        'generation',
        'lease_expires_at',
        'heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function normalizationRun(): BelongsTo
    {
        return $this->belongsTo(NormalizationRun::class);
    }
}
