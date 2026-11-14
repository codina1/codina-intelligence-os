# Phase 02C Schema

Additive MariaDB migrations (`2024_01_01_000400`–`000450`). No destructive changes to Phase 01/02A/02B tables.

## Tables

### content_entities

Tenant-scoped content read model. Unique `(data_source_id, entity_type, external_id_hash)`. Composite FKs to `data_sources` and `sites`. Unique `(tenant_id, id)` for child FKs. No circular FK to versions.

### content_versions

Append-only versions. Unique `(content_entity_id, version_number)`. Stores normalized JSON + mapper_version + source ingestion record id.

### content_source_links

Provenance only. Unique `(content_entity_id, data_source_id, relation_type)`. No cross-provider equivalence.

### metric_observations

Independent metric facts. Unique `(data_source_id, observation_identity_hash)`. Nullable periods/dimensions. Zero values preserved.

### normalization_runs

Run counters + status (`queued|running|completed|completed_with_errors|failed`). `is_simulated=true` for mock.

### normalization_failures

Safe diagnostics JSON only — no secrets/PII/raw rejected payloads.
