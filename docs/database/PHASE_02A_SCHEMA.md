# Phase 02A Schema

Additive MariaDB 10.11 migrations on isolated local/test databases only.

## Migrations

| Migration | Purpose |
| --- | --- |
| `2024_01_01_000200_add_sites_tenant_composite_index` | Unique `(tenant_id, id)` on `sites` for composite FKs |
| `2024_01_01_000210_create_data_sources_table` | Registry core + generated `site_scope_id` uniqueness |
| `2024_01_01_000220_create_data_source_capabilities_table` | Per-source capability rows |
| `2024_01_01_000230_create_credential_references_table` | Mock-safe credential references |
| `2024_01_01_000240_create_data_source_health_checks_table` | Append-only health history |
| `2024_01_01_000250_create_data_source_configuration_versions_table` | Sanitized settings history |

## data_sources

| Column | Notes |
| --- | --- |
| `public_id` | UUID, unique external identifier |
| `tenant_id` | FK → tenants |
| `site_id` | Nullable; composite FK `(tenant_id, site_id)` → `sites(tenant_id, id)` |
| `provider` | One of five mock providers |
| `mode` | `mock` only in Phase 02A |
| `status` | `draft`, `configured`, `healthy`, `error`, `disabled` |
| `settings` | Allowlisted JSON only |
| `site_scope_id` | Generated `IFNULL(site_id, 0)` for null-safe unique `(tenant_id, provider, site_scope_id)` |

## Constraints

- Unique `(tenant_id, id)` on `data_sources` for child composite FKs.
- Child tables enforce `(tenant_id, data_source_id)` → `data_sources(tenant_id, id)`.
- Unique capability per source; unique credential type per source; unique configuration version number per source.
- Health checks are insert-only history (no update API).

## Non-goals

No production DSN. No live OAuth token columns. No sync cursor tables (Phase 02B).
