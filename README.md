# CODINA Intelligence OS

Multi-tenant content and business intelligence platform.

## Phase 01

Foundation, identity, multi-tenancy, mock connectors, queue/audit, and versioned REST API.

See `docs/` for architecture, schema, security, OpenAPI, and test evidence.

## Local requirements

- PHP 8.3+
- Composer 2
- MariaDB 10.6+
- Node.js (optional for frontend; Phase 01 is API-focused)

## Quick start

```bash
cp .env.example .env
composer install
php artisan key:generate
# Configure DB_* to a disposable local MariaDB database
php artisan migrate
php artisan db:seed
php artisan serve
```

**Production access is forbidden for this phase.**
