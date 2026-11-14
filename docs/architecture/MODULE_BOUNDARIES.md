# Module Boundaries — Phase 01

## Identity

**Owns:** users, roles, permissions, login/logout/me, permission checks.  
**May call:** Audit (login events).  
**Must not:** create external connector calls or mutate other tenants’ sites without Tenancy/Sites services.

## Tenancy

**Owns:** tenants, memberships, `TenantContext`, tenant listing/create.  
**May call:** Identity authorization, Audit.  
**Must not:** trust client-supplied tenant IDs without membership validation.

## Sites

**Owns:** site CRUD scoped to authorized tenants.  
**May call:** Tenancy context, Identity authorization, Audit.  
**Must not:** reassign `tenant_id` across tenants.

## Connectors

**Owns:** `ExternalConnectorInterface`, mock implementations, fixture loading, registry.  
**May call:** Shared config only.  
**Must not:** perform real HTTP, OAuth, or store production credentials.

## Synchronization

**Owns:** tenant-aware queued job skeleton carrying explicit tenant + user ids.  
**May call:** Tenancy context revalidation.  
**Must not:** sync real external systems in Phase 01.

## Audit

**Owns:** `audit_logs` writes and payload redaction.  
**May call:** nothing domain-specific beyond models.  
**Must not:** store secrets in clear text.

## Shared Contracts

**Owns:** API response helpers, idempotency guard, AI provider interface + null implementation.  
**May be used by:** all modules.  
**Must not:** contain business workflows for later phases.
