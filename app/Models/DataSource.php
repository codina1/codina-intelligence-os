<?php

namespace App\Models;

use App\Connectors\Enums\DataSourceMode;
use App\Connectors\Enums\DataSourceStatus;
use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DataSource extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'site_id',
        'provider',
        'display_name',
        'external_resource_id',
        'mode',
        'status',
        'auth_method',
        'settings',
        'last_tested_at',
        'last_success_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'mode' => DataSourceMode::class,
            'status' => DataSourceStatus::class,
            'last_tested_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(DataSourceCapability::class);
    }

    public function credentialReferences(): HasMany
    {
        return $this->hasMany(CredentialReference::class);
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(DataSourceHealthCheck::class);
    }

    public function configurationVersions(): HasMany
    {
        return $this->hasMany(DataSourceConfigurationVersion::class);
    }

    public function latestCredentialReference(): HasOne
    {
        return $this->hasOne(CredentialReference::class)->latestOfMany();
    }
}
