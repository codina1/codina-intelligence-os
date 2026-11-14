<?php

namespace App\Normalization\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Normalization\Services\NormalizationOrchestrator;
use App\Tenancy\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class NormalizationRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $normalizationRunId,
        public int $tenantId,
        public int $userId,
    ) {}

    public function handle(TenantContext $tenantContext, NormalizationOrchestrator $orchestrator): array
    {
        try {
            $tenant = Tenant::query()->find($this->tenantId);
            $user = User::query()->find($this->userId);

            if ($tenant === null || $user === null) {
                throw new RuntimeException('Normalization job missing tenant or user.');
            }

            if (! $user->isPlatformAdmin() && ! in_array($this->tenantId, $user->accessibleTenantIds(), true)) {
                throw new RuntimeException('Normalization job rejected: cross-tenant access.');
            }

            $orchestrator->executeQueuedRun($this->normalizationRunId, $this->tenantId, $this->userId);

            return [
                'ok' => true,
                'normalization_run_id' => $this->normalizationRunId,
                'tenant_id' => $this->tenantId,
                'action' => 'normalization.execute',
            ];
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $tenantContext->clear();
        }
    }
}
