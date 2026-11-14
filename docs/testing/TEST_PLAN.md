# Test Plan — Phase 01

## Framework

PHPUnit 12 via `php artisan test` / `./vendor/bin/phpunit`  
Database: MariaDB `codina_ios_test` (verified local)

## Suites

### Environment

- Application boot / env
- DB connectivity
- Schema presence after migrations
- Safe configuration (no external HTTP, non-production)

### Identity

- Session login success/failure
- 401 unauthenticated
- Role/permission seeding
- Viewer denied site manage (403)

### Tenancy

- Platform admin tenant create
- Tenant-scoped site listing
- IDOR prevention
- Cross-tenant create denial
- Queue tenant context revalidation
- Tenant index filtering

### Mock connectors

- Success fetch
- Pagination
- Error scenarios
- Zero external HTTP
- Registry lists five connectors

### API

- Response envelope + schema_version
- Validation errors
- AuthZ/AuthN errors
- Pagination meta
- Request ID echo

### Infrastructure

- Queue dispatch/process
- Failed cross-tenant job path
- Audit log + redaction
- Idempotency replay

## Execution policy

Never mark unexecuted tests as PASS. Record real command output in `TEST_RESULTS.md`.
