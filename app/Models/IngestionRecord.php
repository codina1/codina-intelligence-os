<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionRecord extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'data_source_id',
        'entity_type',
        'external_id',
        'external_id_hash',
        'payload_hash',
        'payload_json',
        'first_seen_at',
        'last_seen_at',
        'last_changed_at',
        'last_sync_run_id',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_changed_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
