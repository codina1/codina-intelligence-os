# Content Inventory API (Phase 02C)

All routes require authenticated SPA session. Mutating routes require CSRF. Mock flags (`is_simulated`, `mock_only`) always true in this phase.

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/inventory/summary` | `inventory.view` | Counts by content type / provider |
| GET | `/api/v1/inventory/entities` | `inventory.view` | Filters: content_type, data_source_id, sort |
| GET | `/api/v1/inventory/entities/{public_id}` | `inventory.view` | Cross-tenant → 404 |
| GET | `/api/v1/inventory/entities/{public_id}/versions` | `inventory.view` | Append-only history |
| GET | `/api/v1/inventory/metrics` | `inventory.view` | No auto-linked content entity ids |
| GET | `/api/v1/inventory/data-quality` | `inventory.view` | Missing vs unsupported vs failure vs not synced |
| POST | `/api/v1/data-sources/{public_id}/normalize` | `normalize.manage` (or sync/connectors manage) | **202** |
| GET | `/api/v1/normalization-runs/{public_id}` | `inventory.view` | Run counters |

Pagination via `per_page` (max 100). Envelope: `{ data, meta }` with `schema_version` and `request_id`.
