<?php

namespace App\Tenancy\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\Exceptions\TenantContextRequiredException;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->tenant_id === null) {
                $tenantId = app(TenantContext::class)->id();
                if ($tenantId === null) {
                    throw new TenantContextRequiredException(
                        'Cannot create a tenant-owned model without tenant context or tenant_id.'
                    );
                }
                $model->tenant_id = $tenantId;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Explicit escape hatch for membership-scoped multi-tenant reads.
     * Callers MUST still constrain by authorized tenant IDs.
     */
    public function scopeWithoutTenantIsolation(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
