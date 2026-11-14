# Phase 02A Test Plan

## Suites

| Suite | Location | Coverage |
| --- | --- | --- |
| Registry | `tests/Feature/Connectors/DataSourceRegistryTest.php` | Providers, CRUD, validation, lifecycle, duplicates, mock scenarios, filters, FK integrity |
| Security | `tests/Feature/Connectors/DataSourceSecurityTest.php` (`@group critical`) | Authn/z, isolation, CSRF, idempotency, audit, secret non-exposure |
| Phase 01 regression | Existing Feature/Unit tests | Must remain green |

## Scenarios required by master prompt

- Migrations + FK + uniqueness + configuration versions
- Five providers + valid mock create + config validation
- Update/disable + invalid transitions + duplicates
- Unauthenticated / unauthorized / tenant isolation / non-enumeration
- CSRF + idempotency + audit
- No secret persistence/exposure
- Mock success/failure + health history + no external HTTP
- Disabled sources cannot test

## CI order

1. MariaDB migrations  
2. Phase 01 regression  
3. Phase 02A tests  
4. Critical group with `--fail-on-skipped`  
5. Full PHPUnit suite  
