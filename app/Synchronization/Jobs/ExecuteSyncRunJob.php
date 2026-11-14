<?php

namespace App\Synchronization\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Synchronization\Services\SyncOrchestrator;
use App\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class ExecuteSyncRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $syncRunId,
        public int $tenantId,
        public int $userId,
    ) {}

    public function handle(TenantContext $tenantContext, SyncOrchestrator $orchestrator): array
    {
        try {
            $tenant = Tenant::query()->find($this->tenantId);
            $user = User::query()->find($this->userId);

            if ($tenant === null || $user === null) {
                throw new RuntimeException('Sync job missing tenant or user.');
            }

            if (! $user->isPlatformAdmin() && ! in_array($this->tenantId, $user->accessibleTenantIds(), true)) {
                throw new RuntimeException('Sync job rejected: cross-tenant access.');
            }

            // Do not trust serialized job params as authorization — orchestrator re-checks
            // source existence, mock mode, disabled state, and lease ownership.
            $orchestrator->executeQueuedRun($this->syncRunId, $this->tenantId, $this->userId);

            return [
                'ok' => true,
                'sync_run_id' => $this->syncRunId,
                'tenant_id' => $this->tenantId,
                'action' => 'sync.execute',
            ];
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $tenantContext->clear();
        }
    }
}
