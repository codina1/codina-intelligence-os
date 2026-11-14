# Sync Engine API (Phase 02B)

All endpoints require session auth + established API envelope. Mutations require CSRF.  
Sync results are always simulated (`is_simulated: true`).

## Endpoints

| Method | Path | Permission |
| --- | --- | --- |
| `POST` | `/api/v1/data-sources/{id}/sync` | `connectors.manage` or `sync.manage` → **202** |
| `GET` | `/api/v1/sync-runs` | `connectors.view` |
| `GET` | `/api/v1/sync-runs/{id}` | `connectors.view` |
| `GET` | `/api/v1/sync-runs/{id}/attempts` | `connectors.view` |
| `POST` | `/api/v1/sync-runs/{id}/retry` | manage → **202** |
| `POST` | `/api/v1/sync-runs/{id}/cancel` | manage |
| `GET` | `/api/v1/data-sources/{id}/checkpoints` | `connectors.view` |
| `GET` | `/api/v1/data-sources/{id}/sync-health` | `connectors.view` |

## Start body

```json
{
  "sync_type": "full",
  "entity_type": "posts",
  "scope_key": "default"
}
```

Public clients may not submit `lease_owner`, `generation`, fencing tokens, or tenant ownership overrides (stripped from `options`).

Foreign / missing IDs return indistinguishable `404`.
