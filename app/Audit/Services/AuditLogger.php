<?php

namespace App\Audit\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function log(
        string $action,
        ?User $user = null,
        ?Model $auditable = null,
        array $payload = [],
        ?int $tenantId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'tenant_id' => $tenantId ?? $this->tenantContext->id() ?? ($auditable->tenant_id ?? null),
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'payload' => $this->sanitize($payload),
            'ip_address' => request()?->ip(),
            'request_id' => request()?->attributes->get('request_id'),
        ]);
    }

    private function sanitize(array $payload): array
    {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'authorization', 'credentials'];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }

        return $payload;
    }
}
