# Tenant Isolation — Phase 01

## Model

Shared database with row-level tenant ownership (`tenant_id`).

## Controls

1. **Membership-derived access** — `User::accessibleTenantIds()` and `membershipFor()`; platform admins are explicit.
2. **Service-layer checks** — `AuthorizationService::assertTenantAccess` / `assertPermission` before reads and writes.
3. **IDOR protection** — Site show/update/delete load the record then verify tenant membership; foreign sites return 403.
4. **Cross-tenant create blocked** — Creating a site for a non-member tenant returns 403.
5. **TenantScope** — Eloquent global scope applied when `TenantContext` is set.
6. **Queue revalidation** — `TenantAwareJob` carries `tenantId` + `userId` and re-checks membership before work.
7. **No client trust** — Browser-submitted tenant IDs are treated as hints and validated server-side.

## Automated evidence

Feature suite `Tests\Feature\Tenancy\TenantIsolationTest` covers listing isolation, IDOR, cross-tenant FK protection, job context, and tenant index filtering.
