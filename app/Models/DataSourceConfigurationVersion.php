<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSourceConfigurationVersion extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'data_source_id',
        'version_number',
        'settings_snapshot',
        'changed_by',
        'change_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'settings_snapshot' => 'array',
            'created_at' => 'datetime',
            'version_number' => 'integer',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
