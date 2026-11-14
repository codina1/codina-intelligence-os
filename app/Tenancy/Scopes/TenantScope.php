<?php

namespace App\Tenancy\Scopes;

use App\Tenancy\Exceptions\TenantContextRequiredException;
use App\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatformBypassActive()) {
            return;
        }

        $tenantId = $context->id();

        if ($tenantId === null) {
            throw new TenantContextRequiredException(
                'Refusing tenant-owned query without an active tenant context.'
            );
        }

        $builder->where($model->getTable().'.tenant_id', $tenantId);
    }
}
