<?php

namespace App\Synchronization\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class TenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $tenantId,
        public int $userId,
        public string $action = 'sync.ping',
    ) {}

    public function handle(TenantContext $tenantContext): array
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $user = User::query()->find($this->userId);

        if ($tenant === null || $user === null) {
            throw new RuntimeException('Tenant-aware job missing tenant or user.');
        }

        if (! $user->isPlatformAdmin() && ! in_array($this->tenantId, $user->accessibleTenantIds(), true)) {
            throw new RuntimeException('Tenant-aware job rejected: cross-tenant access.');
        }

        $tenantContext->set($tenant);

        return [
            'ok' => true,
            'tenant_id' => $tenantContext->id(),
            'user_id' => $user->id,
            'action' => $this->action,
        ];
    }
}
