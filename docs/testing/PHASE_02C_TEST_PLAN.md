# Phase 02C Test Plan

## Suites

| File | Focus |
| --- | --- |
| `NormalizationMappingTest` | Provider mapping, versioning, zero metrics, no staging mutation, no HTTP |
| `NormalizationIntegrityTest` | No sync → fail, rejection diagnostics, provenance, metric idempotency, schema |
| `InventoryApiTest` | Read APIs, data-quality, role gates |
| `NormalizationSecurityTest` | Cross-tenant 404, CSRF, job isolation, permissions (**critical**) |

## CI

`.github/workflows/phpunit.yml` runs Phase 01 → 02A → 02B → **02C** → critical (`--fail-on-skipped`) → full suite on MariaDB 10.11.
