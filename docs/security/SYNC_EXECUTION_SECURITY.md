# Sync Execution Security (Phase 02B)

- **Mock only:** `CODINA_ALLOW_EXTERNAL_HTTP=false`; non-mock sources refused.
- **Tenant fail-closed:** BelongsToTenant + TenantScope; jobs clear context in `finally`.
- **Authorization:** view via `connectors.view`; start/retry/cancel via `connectors.manage` or `sync.manage`.
- **Non-enumeration:** cross-tenant IDs → 404.
- **CSRF:** SPA CSRF middleware enforced on mutations (not skipped in PHPUnit).
- **Idempotency:** HTTP Idempotency-Key for start/retry; 30s reclaim remains HTTP-only — **not** used for sync leases.
- **Lease fencing:** generation + owner required before page commit; separate 300s TTL.
- **Job trust:** serialized job params are not authorization; orchestrator re-checks source, mode, tenant, lease.
- **Staging:** credential-like / unexpected PII fields rejected; audit payloads sanitized.
- **Http::preventStrayRequests** in tests; no production credentials.
