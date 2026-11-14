<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentEntity extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'site_id',
        'data_source_id',
        'entity_type',
        'external_id',
        'external_id_hash',
        'content_type',
        'title',
        'slug',
        'canonical_url',
        'publication_status',
        'normalized_status',
        'current_version_number',
        'normalized_payload_hash',
        'source_updated_at',
        'first_seen_at',
        'last_seen_at',
        'normalized_at',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'normalized_at' => 'datetime',
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

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function sourceLinks(): HasMany
    {
        return $this->hasMany(ContentSourceLink::class);
    }
}
