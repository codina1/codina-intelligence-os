<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentVersion extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'content_entity_id',
        'version_number',
        'normalized_payload_hash',
        'normalized_payload_json',
        'source_ingestion_record_id',
        'mapper_version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'normalized_payload_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function contentEntity(): BelongsTo
    {
        return $this->belongsTo(ContentEntity::class);
    }
}
