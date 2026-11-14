# Phase 02B Schema

Additive MariaDB migrations (local/CI only):

| Migration | Table |
| --- | --- |
| `2024_01_01_000300_*` | `sync_active_scopes` |
| `2024_01_01_000310_*` | `sync_runs` |
| `2024_01_01_000320_*` | `sync_checkpoints` |
| `2024_01_01_000330_*` | `sync_attempts` |
| `2024_01_01_000340_*` | `ingestion_batches` |
| `2024_01_01_000350_*` | `ingestion_records` |

## Key constraints

- Tenant-aware composite FKs from sync tables → `data_sources (tenant_id, id)`.
- `sync_active_scopes` unique `(data_source_id, entity_type, scope_key)`.
- `sync_checkpoints` unique `(data_source_id, entity_type, scope_key)`.
- `ingestion_batches.commit_key` unique (deterministic per-run page+payload).
- `ingestion_records` unique `(data_source_id, entity_type, external_id_hash)`; original `external_id` verified on hash match.
- Nullable `last_completed_run_id` uses a simple FK (`ON DELETE SET NULL`) because MariaDB rejects composite SET NULL with non-null `tenant_id`.

Staging stores mock-safe JSON only — no credentials or unexpected personal data (validator-enforced).
