# Schema — Phase 01

MariaDB / InnoDB, utf8mb4. Tables implemented:

## users

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| name | string | |
| email | string unique | |
| email_verified_at | timestamp nullable | |
| password | string | hashed |
| is_platform_admin | boolean default false | additive migration |
| status | string indexed | additive migration |
| remember_token | string nullable | |
| timestamps | | |

## tenants

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| name | string | |
| slug | string unique | |
| status | string indexed | active/suspended |
| settings | json nullable | |
| timestamps | | |

## sites

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| tenant_id | FK → tenants | cascade delete |
| name | string | |
| slug | string | unique with tenant_id |
| primary_url | string | |
| status | string indexed | |
| settings | json nullable | |
| timestamps | | |
| indexes | (tenant_id, status), unique(tenant_id, slug) | |

## roles / permissions / role_permissions

- `roles.slug` unique; `is_platform_role` boolean
- `permissions.slug` unique
- `role_permissions` unique(`role_id`,`permission_id`)

## tenant_memberships

| Column | Notes |
| --- | --- |
| tenant_id, user_id, role_id | FKs |
| unique(tenant_id, user_id) | one membership per tenant |
| status, joined_at | |

## audit_logs

Nullable `tenant_id` / `user_id`, morph columns, JSON payload, `request_id`, indexes on tenant+created_at and auditable morph.

## idempotency_keys

Scoped unique key on (`tenant_id`,`user_id`,`key`,`route`) with request hash + stored response.

## jobs / job_batches / failed_jobs

Laravel default queue tables (database driver).

## cache / sessions / password_reset_tokens

Laravel defaults for local session/cache operation.
