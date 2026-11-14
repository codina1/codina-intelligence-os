# Phase 02A Architecture — Data Source Registry

## Scope

Phase 02A adds a tenant-aware **Data Source Registry** and **mock-only connector configuration** on top of the Phase 01 modular monolith. No sync engine, OAuth, or live provider HTTP is included.

**Base commit:** `24a63537b7b15d6445c0215c7d25e5d45cfb9554` (`main` after Phase 01 merge).

## Module layout

| Area | Path | Responsibility |
| --- | --- | --- |
| Provider catalog | `app/Connectors/Registry/ProviderRegistry.php` | Metadata, capability catalog, allowlisted settings schema |
| Existing mocks | `app/Connectors/Mock/*` + `ConnectorRegistry` | Fixture-backed mock connectors (extended with `timeout`) |
| Settings validation | `app/Connectors/Validation/ProviderSettingsValidator.php` | Reject unknown/unsafe settings |
| Lifecycle | `app/Connectors/Services/DataSourceLifecycle.php` | Explicit status transitions |
| Domain service | `app/Connectors/Services/DataSourceService.php` | CRUD, test, disable, isolation |
| Secret seam | `app/Connectors/Contracts/SecretStoreInterface.php` + `NullSecretStore` | Mock references only |
| HTTP API | `DataSourceController`, `ConnectorProviderController` | Versioned JSON endpoints |

## Providers (mock only)

1. `wordpress`
2. `woocommerce`
3. `google_search_console`
4. `google_analytics_4`
5. `tutor_lms_pro`

Each provider advertises capabilities, auth-method **metadata** (not live auth), and an allowlisted settings schema. Mock health never claims real provider connectivity (`verified_real_connection: false`).

## Status lifecycle

`draft` → `configured` → (`healthy` \| `error`) with `disabled` reachable from enabled states. Re-enable is only `disabled` → `configured` with authorization and valid configuration. Disabled sources reject mock tests.

## Tenancy & security

- All registry tables are tenant-owned (`BelongsToTenant` + `TenantScope`).
- Composite FK `(tenant_id, site_id) → sites(tenant_id, id)` enforces site/tenant consistency.
- Child rows use composite FK `(tenant_id, data_source_id) → data_sources(tenant_id, id)`.
- Authorization: `connectors.view` (read), `connectors.manage` (mutate/test/disable).
- Viewer / Analyst / Content Manager do not receive `connectors.manage` by default.
- Platform admin bypass remains explicit and audited.
- Foreign/missing public IDs return indistinguishable `404`.

## Idempotency note

`IdempotencyGuard` is used on `POST /data-sources`. Phase 02A mock tests are short, local, and synchronous — **automatic stale-pending reclaim is not used for long-running connector operations** (none exist in 02A). The Phase 01 stale-claim limitation remains documented; do not enable long-running reclaim for future sync jobs without a redesign.

## External HTTP

`CODINA_ALLOW_EXTERNAL_HTTP=false` is enforced. Mock connector paths refuse execution if the flag is enabled. Tests use `Http::preventStrayRequests()`.
