# Architecture — Phase 01

## Overview

CODINA Intelligence OS Phase 01 is a **Laravel Modular Monolith** with shared-database multi-tenancy. It establishes identity, tenancy, sites, mock external connectors, database queues, audit logging, and a versioned JSON API.

Frontend Vue 3 is reserved for later phases; Phase 01 is API-first.

## Runtime stack

- Laravel 13 / PHP 8.3
- MariaDB 10.11 (local)
- Database queue driver
- Session authentication for first-party API use
- PHPUnit feature tests

## Module map

| Module | Responsibility | Primary locations |
| --- | --- | --- |
| Identity | Users, roles, permissions, session auth | `app/Models/User.php`, `app/Identity/`, `AuthSessionController` |
| Tenancy | Tenants, memberships, tenant context | `app/Tenancy/`, `app/Models/Tenant.php`, `TenantMembership` |
| Sites | Tenant-owned sites | `app/Sites/`, `app/Models/Site.php` |
| Connectors | Mock external connector contracts/registry | `app/Connectors/` |
| Synchronization | Tenant-aware queued job foundation | `app/Synchronization/Jobs/` |
| Audit | Audit log writer with secret redaction | `app/Audit/` |
| Shared Contracts | API envelope, idempotency, AI placeholder | `app/Shared/` |

## Request flow

1. `AssignRequestId` attaches `X-Request-Id` / correlation id.
2. `EnsureJsonApi` forces JSON Accept for API routes.
3. Session middleware enables first-party session auth.
4. Controllers stay thin; services enforce authorization and tenant checks.
5. Responses use `{ data, meta }` or `{ error, meta }` envelopes.

## Multi-tenancy model

- Shared MariaDB schema.
- Tenant ownership via `tenant_id` foreign keys.
- Global `TenantScope` on tenant-owned models when context is set.
- Authorization derives access from memberships (or platform admin flag), never from untrusted browser tenant claims alone.

## Explicit non-goals (Phase 01)

- Real WordPress / WooCommerce / GSC / GA4 / Tutor LMS HTTP
- OAuth / credential exchange
- Content generation, SEO recommendations, publication
- AI provider calls (interface placeholder only)
- Production deployment
