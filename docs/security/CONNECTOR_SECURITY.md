# Connector Security — Phase 02A

## Guarantees

1. **Mock only** — `CODINA_ALLOW_EXTERNAL_HTTP=false`; mock connectors throw if enabled.
2. **No real secrets** — settings validator rejects credential/URL fields; `NullSecretStore` accepts only `mock:` values.
3. **No secret exposure** — `CredentialReference.secret_reference` is `$hidden`; API returns safe metadata only (`has_reference`, type, status).
4. **Audit sanitization** — AuditLogger redacts `secret_reference`, tokens, passwords, etc.
5. **Tenant isolation** — membership-scoped queries; composite FKs; cross-tenant site refs rejected; foreign IDs → `404`.
6. **Authorization** — `connectors.view` / `connectors.manage`; Viewer, Analyst, and Content Manager cannot manage by default.
7. **CSRF** — all mutations require SPA CSRF tokens.
8. **Disabled sources** — cannot run mock tests until re-enabled to `configured`.
9. **Mock health honesty** — results set `verified_real_connection: false` and mark simulated latency.

## Platform bypass

Platform admin reads use `TenantContext::runWithPlatformBypass` and emit `platform.tenant_bypass` audit events.

## Idempotency

Create uses `IdempotencyGuard`. Long-running connector reclaim remains **disabled**; Phase 02A tests are synchronous and local.
