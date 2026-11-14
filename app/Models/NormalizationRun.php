<?php

namespace App\Models;

use App\Normalization\Enums\NormalizationRunStatus;
use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NormalizationRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'data_source_id',
        'status',
        'mapper_version',
        'records_scanned',
        'entities_created',
        'entities_updated',
        'entities_unchanged',
        'versions_created',
        'metrics_created',
        'metrics_updated',
        'records_rejected',
        'started_at',
        'finished_at',
        'last_error_code',
        'requested_by',
        'is_simulated',
    ];

    protected function casts(): array
    {
        return [
            'status' => NormalizationRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'is_simulated' => 'boolean',
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

    public function failures(): HasMany
    {
        return $this->hasMany(NormalizationFailure::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
