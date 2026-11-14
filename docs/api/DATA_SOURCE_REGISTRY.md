# Data Source Registry API

Base path: `/api/v1`  
Auth: session cookie + CSRF on mutations  
Envelope: `{ data, meta }` / `{ error, meta }`

## Endpoints (implemented)

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/connector-providers` | `connectors.view` | Provider catalog + settings schema |
| GET | `/connector-providers/{key}` | `connectors.view` | Single provider |
| GET | `/data-sources` | `connectors.view` | Filters: `site`, `provider`, `status`, `mode`, `tenant_id` |
| POST | `/data-sources` | `connectors.manage` | Mock mode only; Idempotency-Key supported |
| GET | `/data-sources/{public_id}` | `connectors.view` | UUID public id |
| PATCH | `/data-sources/{public_id}` | `connectors.manage` | Settings/status updates |
| POST | `/data-sources/{public_id}/test` | `connectors.manage` | Mock fixtures; records health history |
| GET | `/data-sources/{public_id}/health` | `connectors.view` | Append-only history |
| GET | `/data-sources/{public_id}/capabilities` | `connectors.view` | Capability rows |
| POST | `/data-sources/{public_id}/disable` | `connectors.manage` | Sets status `disabled` |

## Allowlisted settings

`fixture_dataset`, `pagination_size`, `simulated_error_scenario`, `reporting_timezone`, `mock_external_resource_id`

Unknown keys, URL-like values, and credential-like fields are rejected with `422`.

## Not implemented (future phases)

- Live OAuth / credential exchange
- Real connection verification
- Sync engine / Phase 02B endpoints
- Returning `secret_reference` values in API payloads
