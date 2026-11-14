<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentSourceLink extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'content_entity_id',
        'data_source_id',
        'ingestion_record_id',
        'relation_type',
    ];

    public function contentEntity(): BelongsTo
    {
        return $this->belongsTo(ContentEntity::class);
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
