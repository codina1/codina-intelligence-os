<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricObservation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'site_id',
        'data_source_id',
        'source_ingestion_record_id',
        'provider',
        'metric_key',
        'metric_value',
        'unit',
        'dimensions_json',
        'observation_identity_hash',
        'period_start',
        'period_end',
        'observed_at',
        'source_payload_hash',
        'mapper_version',
    ];

    protected function casts(): array
    {
        return [
            'metric_value' => 'decimal:6',
            'dimensions_json' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'observed_at' => 'datetime',
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
