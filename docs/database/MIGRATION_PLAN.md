# Migration Plan — Phase 01

## Strategy

Additive, ordered migrations. No destructive operations against unverified databases.

## Order

1. `0001_01_01_000000_create_users_table` — users, password resets, sessions
2. `0001_01_01_000001_create_cache_table` — cache tables
3. `0001_01_01_000002_create_jobs_table` — jobs, job_batches, failed_jobs
4. `2024_01_01_000100_create_tenants_table`
5. `2024_01_01_000110_create_sites_table`
6. `2024_01_01_000120_create_roles_and_permissions_tables`
7. `2024_01_01_000130_create_tenant_memberships_table`
8. `2024_01_01_000140_create_audit_logs_table`
9. `2024_01_01_000150_create_idempotency_keys_table`
10. `2024_01_01_000160_add_is_platform_admin_to_users_table` — additive user columns

## Execution

```bash
# Only after verifying DB_* points at disposable local DB
php artisan migrate
php artisan db:seed
```

## Safety

- Never run `migrate:fresh` / `db:wipe` against unverified hosts.
- Tests use `codina_ios_test` only.
- App uses `codina_ios_local` only.
- Production seeding is refused in `DatabaseSeeder`.
