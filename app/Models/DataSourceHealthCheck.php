<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSourceHealthCheck extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'data_source_id',
        'check_type',
        'status',
        'duration_ms',
        'error_code',
        'result_metadata',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'result_metadata' => 'array',
            'checked_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
