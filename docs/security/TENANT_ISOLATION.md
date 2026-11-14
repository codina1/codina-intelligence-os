# Tenant Isolation — Phase 01

## Model

Shared database with row-level tenant ownership (`tenant_id`).

## Controls

1. **Membership-derived access** — `User::accessibleTenantIds()` and `membershipFor()`; platform admins are explicit.
2. **Service-layer checks** — `AuthorizationService::assertTenantAccess` / `assertPermission` before reads and writes.
3. **IDOR protection / anti-enumeration** — Site show/update/delete use membership-scoped queries only; inaccessible and nonexistent IDs both return **404** with identical messages (no global existence probe).
4. **Cross-tenant create blocked** — Creating a site for a non-member tenant returns 403.
5. **Fail-closed TenantScope** — Tenant-owned Eloquent queries throw `TenantContextRequiredException` when no tenant context is active (no silent unscoped reads).
6. **Explicit escapes only** — `withoutTenantIsolation()` for membership-constrained multi-tenant reads; `TenantContext::runWithPlatformBypass()` for audited platform-wide access.
7. **Queue revalidation + cleanup** — `TenantAwareJob` re-checks membership and clears `TenantContext` in `finally`.
8. **No client trust** — Browser-submitted tenant IDs are hints validated server-side.

## Automated evidence

`Tests\Feature\Tenancy\TenantIsolationTest` covers listing isolation, IDOR, cross-tenant FK protection, job context, missing/stale context fail-closed behavior, and audited platform bypass.
