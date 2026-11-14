<?php

namespace App\Models;

use App\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialReference extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'data_source_id',
        'credential_type',
        'secret_reference',
        'status',
        'expires_at',
    ];

    protected $hidden = [
        'secret_reference',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /**
     * @return array{credential_type: string, status: string, expires_at: ?string, has_reference: bool}
     */
    public function toSafeArray(): array
    {
        return [
            'credential_type' => $this->credential_type,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'has_reference' => $this->secret_reference !== null && $this->secret_reference !== '',
        ];
    }
}
